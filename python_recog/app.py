from flask import Flask, jsonify, request
import face_utils

app = Flask(__name__)
app.config["MAX_CONTENT_LENGTH"] = 32 * 1024 * 1024


def bad_request(message):
    return jsonify({"ok": False, "code": "bad_request", "message": message}), 400



@app.get("/health")
def health():
    return jsonify({"ok": True})



@app.post("/enroll")
def enroll():
    data = request.get_json(silent=True)
    if (not isinstance(data, dict) or not isinstance(data.get("user_id"), str) 
            or not isinstance(data.get("images"), list)):
        return bad_request('Send JSON like {"user_id": "E001", "images": ["<base64>", ...]}')

    if len(data["images"]) > face_utils.config["max_samples"]:
        return bad_request("Too many images (max %d)." % face_utils.config["max_samples"])

    return jsonify(face_utils.enroll_user(data["user_id"], data["images"], replace=bool(data.get("replace"))))



@app.post("/detect")
def detect():
    data = request.get_json(silent=True)
    if not isinstance(data, dict) or not isinstance(data.get("image"), str):
        return bad_request('Send JSON like {"image": "<base64>"}')

    return jsonify(face_utils.detect(data["image"]))



@app.post("/recognize")
def recognized():
    data = request.get_json(silent=True)
    if not isinstance(data, dict) or not isinstance(data.get("image"), str):
        return bad_request('Send JSON like {"image": "<base64>"}')

    return jsonify(face_utils.recognize(data["image"]))


@app.post("/delete")
def delete():
    data = request.get_json(silent=True)
    if not isinstance(data, dict) or not isinstance(data.get("user_id"), str):
        return bad_request('Send JSON like {"user_id": "E001"}')

    return jsonify(face_utils.delete_user(data["user_id"]))



if __name__ == "__main__":
    app.run(host="127.0.0.1", port=8765, debug=False)
