import base64
import os
import re
import shutil
import threading
import cv2
import numpy as np
from sklearn.neighbors import KNeighborsClassifier

base_dir = os.path.dirname(os.path.abspath(__file__))
faces_dir = os.environ.get("FACE_DATA_DIR", os.path.join(base_dir, "..", "data", "faces"))
haar_path = os.path.join(base_dir, "haarcascade_frontalface_default.xml")


config = {
    "image_size": (100, 100),
    "k": 5,
    "distance_threshold": 3500,
    "min_vote_share": 0.6,
    "min_face_ratio": 0.20,
    "min_brightness": 60,
    "max_width": 640,
    "min_samples": 15,
    "max_samples": 40,
}

messages = {
    "no_face": "No face detected. Look at the camera.",
    "multiple_faces": "More than one face detected. One person only.",
    "too_small": "Move closer to the camera.",
    "too_dark": "Too dark. Move to a brighter place.",
    "bad_image": "The image could not be read."
}

user_id_pattern = re.compile(r"^[A-Za-z0-9][A-Za-z0-9\-]{2,19}$")


def _error(code, message=None):
    return {"ok": False, "code": code, "message": message or messages.get(code, code)}


def decode_image(data):
    try: 
        if not isinstance(data, str) or not data:
            return None

        if "," in data[:100]:
            data = data.split(",", 1)[1]

        raw = base64.b64decode(data, validate=False)
        bgr = cv2.imdecode(np.frombuffer(raw, np.uint8), cv2.IMREAD_COLOR)
        if bgr is None:
            return None
        h, w = bgr.shape[:2]
        if w > config["max_width"]:
            bgr = cv2.resize(bgr, (config["max_width"], int(h * config["max_width"] / w)))
        return bgr

    except Exception:
        return None



def preprocess(bgr):
    gray = cv2.cvtColor(bgr, cv2.COLOR_BGR2GRAY)
    clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8))

    return gray, clahe.apply(gray)


_cascade = None
_cascade_lock = threading.Lock()


def _get_cascade():
    global _cascade
    if _cascade is None:
        path = haar_path
        if not os.path.exists(path) and hasattr(cv2, "data"):
            path = os.path.join(cv2.data.haarcascades, "haarcascade_frontalface_default.xml")

        _cascade = cv2.CascadeClassifier(path)
        if _cascade.empty():
            raise FileNotFoundError ("Put haarcascade_frontal_default.xml in the python_recog folder.")

    return _cascade


def find_faces(equalized): 
    with _cascade_lock:
        faces = _get_cascade().detectMultiScale(equalized, scaleFactor=1.1, minNeighbors=5, minSize = (50, 50))

    return [tuple(int(v) for v in f) for f in faces]


def extract_face(equalized, box):
    x, y, w, h = box
    return cv2.resize(equalized[y:y + h, x:x + w], config["image_size"])


def analyze(bgr):
    if bgr is None:
        return _error("bad_image")

    gray, equalized = preprocess(bgr)
    faces = find_faces(equalized)

    if faces:
        biggest = max(f[2] for f in faces)
        faces = [f for f in faces if f[2] >= 0.5 * biggest]

    if len(faces) == 0:
        return _error("no_face") 
    if len(faces) > 1:
        return _error("multiple_faces")

    box = faces[0]
    x, y, w, h = box

    if w / bgr.shape[1] < config["min_face_ratio"]:
        r = _error("too_small"); r["box"] = box
        return r
    if gray[y:y + h, x:x + w].mean() < config["min_brightness"]:
        r = _error("too_dark"); r["box"] = box
        return r

    return {"ok": True, "box": box, "face": extract_face(equalized, box)}       


def detect(image_b64):
    bgr = decode_image(image_b64)
    r = analyze(bgr)
    box = r.get("box")
    return {
        "ok": r["ok"],
        "code": "ok" if r["ok"] else r["code"],
        "message": "Face detected." if r["ok"] else r["message"],
        "box": list(box) if box else None,
        "image_width": int(bgr.shape[1]) if bgr is not None else 0,
        "image_height": int(bgr.shape[0]) if bgr is not None else 0,
    }

_lock = threading.RLock()
_model = None


def _user_dir(user_id):
    return os.path.join(faces_dir, user_id)


def load_dataset():
    x, y = [], []
    if not os.path.isdir(faces_dir):
        return np.empty((0, 0 )), []
    for user_id in sorted(os.listdir(faces_dir)):
        folder = _user_dir(user_id)
        if not os.path.isdir(folder) or not user_id_pattern.match(user_id):
            continue
        for name in sorted(os.listdir(folder)):
            img = cv2.imread(os.path.join(folder, name), cv2.IMREAD_GRAYSCALE)
            if img is not None and img.shape == config["image_size"][::-1]:
                x.append(img.flatten())
                y.append(user_id)

    return (np.array(x, dtype=np.float32) if x else np.empty((0, 0))), y


def _train():
    x, y = load_dataset()
    if len(x) == 0:
        return None
    k = min(config["k"], len(x))
    knn = KNeighborsClassifier(n_neighbors=k, metric="euclidean")
    knn.fit(x, y)
    return knn


def get_model():
    global _model
    with _lock:
        if _model is None:
            _model = _train()
        return _model


def invalidate_model():
    global _model
    with _lock:
        _model = None


def enroll_frames(user_id, frames_bgr, replace=False):
    if not isinstance(user_id, str) or not user_id_pattern.match(user_id):
        return _error("bad_user_id", "Invalid user ID.")

    faces, skipped = [], []
    for i, frame in enumerate(frames_bgr):
        r = analyze(frame)
        if r["ok"]:
            faces.append(r["face"])
        else:
            skipped.append({"index": i, "code": r["code"], "message": r["message"]})

    if len(faces) < config["min_samples"]:
        hint = skipped[0]["message"] if skipped else ""
        return {"ok": False, "code": "not_enough_samples", "skipped": skipped, "message": "Only %d clear face photo(s), at least %d needed. %s" % (
            len(faces), config["min_samples"], hint)}

    faces = faces[:config["max_samples"]]

    with _lock:
        final = _user_dir(user_id)
        if os.path.exists(final) and not replace:
            return _error("user_exists", "This user already has a face registered.")

        os.makedirs(faces_dir, exist_ok=True)
        try:
            shutil.rmtree(final, ignore_errors=True)
            os.makedirs(final, exist_ok=True)
            for i, face in enumerate(faces):
                if not cv2.imwrite(os.path.join(final, "%d.png" % i), face):
                    raise OSError("could not write a photo")
        except OSError as e:
            shutil.rmtree(final, ignore_errors=True)
            return _error("save_failed", "Could not save the face photos: %s" % e)
        invalidate_model()

    return {"ok": True, "saved": len(faces), "skipped": skipped}


def enroll_user(user_id, images_b64, replace=False):
    return enroll_frames(user_id, [decode_image(b) for b in images_b64], replace)


def delete_user(user_id):
    if not isinstance(user_id, str) or not user_id_pattern.match(user_id):
        return _error("bad_user_id", "Invalid user ID.")
    with _lock:
        existed = os.path.isdir(_user_dir(user_id))
        shutil.rmtree(_user_dir(user_id), ignore_errors=True)
        invalidate_model()

    return {"ok": True, "removed": existed}
    

def recognize_frame(bgr):
    r = analyze(bgr)
    if not r["ok"]:
        return r

    knn = get_model()
    if knn is None:
        return _error("no_users", "No faces have been registered yet.")

    features = r["face"].flatten().astype(np.float32).reshape(1, -1)

    distances, _ = knn.kneighbors(features)
    avg_distance = float(np.mean(distances))
    probabilities = knn.predict_proba(features)[0]
    best = int(np.argmax(probabilities))
    label, vote_share = str(knn.classes_[best]), float(probabilities[best])

    matched = avg_distance <= config["distance_threshold"] and vote_share >= config["min_vote_share"]
    return {"ok": True, "matched": matched, "user_id": label if matched else None, "distance": avg_distance, "votes": vote_share, "box": r["box"]}


def recognize(image_b64):
    return recognize_frame(decode_image(image_b64))
