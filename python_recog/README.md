# Member 3: face detection and recognition (Python, KNN)

Detection and recognition run in **Python**. A Haar cascade finds the face, the face is cut out,
equalized (CLAHE), resized to 100x100 grayscale (10,000 pixel values), and a KNN classifier
(k=5, Euclidean distance) decides whose saved photos are closest. The browser only opens the
camera and takes photos (`js/face.js`). PHP passes them to Python (`includes/python_client.php`).

```
browser (js/face.js)  --photos-->  PHP  --python_client.php-->  Python service (python_recog/app.py)
   camera, live box                                              Haar + CLAHE + KNN, saves faces
```

## Files

| File | What it does |
|---|---|
| `python_recog/face_utils.py` | detection, recognition, enroll, delete (all the face logic) |
| `python_recog/app.py` | Flask service on `http://127.0.0.1:8765` |
| `python_recog/calibrate.py` | suggests a `distance_threshold` once 2+ people are registered |
| `python_recog/requirements.txt` | flask, numpy, opencv-python, scikit-learn |
| `python_recog/haarcascade_frontalface_default.xml` | OpenCV's face detector file |
| `includes/python_client.php` | PHP class `PythonClient` that calls the service |
| `api/face_detect.php` | relay for the live box and status text |
| `js/face.js` | camera, photo capture, live box (`window.Face`) |

Face photos are saved by Python in `data/faces/<user_id>/` (25 small PNG files per person).
This folder is ignored by git and **must stay blocked from the web**. `users.csv` does not store
face data (leave its `face_descriptor` column empty).

## Setup (once)

```
cd python_recog
py -m pip install -r requirements.txt
```

## Run

Start the Python service and keep its window open (the site needs it):

```
cd python_recog
py app.py
```

Then run the site as usual (XAMPP, or `php -S localhost:8000` in the project folder).
The camera only works on `http://localhost` or `https://`.

To tune the match threshold after registering 2+ people: `py calibrate.py`.

## For Members 2 and 5 (what to call)

Python is at `http://127.0.0.1:8765`. Only PHP talks to it. Use the PHP class:

```php
require_once __DIR__ . '/../includes/python_client.php';   // path depends on where your file is
$py = new PythonClient();
```

### Register (Member 2)

```js
// the page loads js/face.js
await Face.startCamera(video);
Face.startLoop(video, canvas, null, { mirrored: true });   // optional: live box + status text
const photos = await Face.captureFrames(video, 25, 300, (taken, total, status) => {
  // show taken/total, and status.message while no face is seen (e.g. "No face detected")
});
// send `photos` (array of text) to your PHP register endpoint as JSON
```

`captureFrames` only takes a photo while exactly one good face is seen. It waits when there is no
face, so registration can take longer than 8 seconds. It gives up after 60 seconds and returns
the photos it has. Ask the user to turn their head slightly while it runs.

```php
// register endpoint
// 1. validate the form, and make the ID canonical: $id = strtoupper(trim($_POST['employee_id']));
//    check it is unique in users.csv (Member 1)
$r = $py->enroll($id, $photos);        // saves the face photos
if (!$r['ok']) { /* show $r['message']. $r['code'] is one of:
                    not_enough_samples | user_exists | bad_user_id | save_failed | service_down */ }
// 2. save the user to users.csv (Member 1). If that fails:  $py->deleteUser($id);
```

### Login (Member 5)

```js
await Face.startCamera(video);
Face.startLoop(video, canvas, null, { mirrored: true });   // optional: live box + status text
const photo = Face.captureFrame(video);                    // one photo as text -> send to your PHP login endpoint
```

```php
$r = $py->recognize($photo);
if (!$r['ok'])         { /* show $r['message']. $r['code'] is one of:
                            no_face | multiple_faces | too_small | too_dark
                            | bad_image | no_users | service_down */ }
elseif ($r['matched']) { /* $r['user_id'] -> look it up in users.csv (stop if missing) -> go to pin.php */ }
else                   { /* unknown face */ }
```

Never send `$r['distance']` or `$r['votes']` to the browser (they are for tuning only).

If your pages are in a different folder than the project root, set `Face.config.detectUrl` to the
right path of `api/face_detect.php` before calling `Face.startLoop`.

### Keeping faces and users.csv in sync

- Use one `user_id` everywhere, written the same way (uppercase). IDs must match `^[A-Za-z0-9][A-Za-z0-9\-]{2,19}$`.
- When a user is deleted, call `$py->deleteUser($id)` after removing the CSV row.
- At login, always look the user up in `users.csv` and stop if they are missing.
- If you delete a face folder by hand, restart `py app.py` (the service keeps the model in memory).

### Not covered here: liveness (Member 4)

`Face.*` does not give face landmarks, so a blink or head-turn check cannot use it.

## Settings

All in the `config` block at the top of `python_recog/face_utils.py`:

| Setting | Meaning |
|---|---|
| `distance_threshold` | Average distance of the 5 closest photos must be **at or below** this. Run `calibrate.py` to choose it. |
| `min_vote_share` (0.6) | At least 3 of the 5 closest photos must belong to the same person. |
| `min_face_ratio` (0.20) | Face must be at least 20% of the image width ("move closer"). |
| `min_brightness` (60) | Average face brightness before CLAHE ("too dark"). |
| `min_samples` / `max_samples` | 15 to 40 good photos per registration. |

## Tips for good accuracy

- Register in the same kind of lighting people will log in under, face centered and facing the camera.
- Turn the head slightly and change expression a little during registration.
- If someone is often wrongly marked "Unknown", re-register them in better light.
- Test with several classmates, on different days, before the demo. Lighting is the main weakness of raw-pixel KNN.