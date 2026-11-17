/**
 * js/liveness.js - Member 4: "is this a live person?" check.
 *
 * Why face-api.js here? Member 3's recognition runs in Python (Haar + KNN) and gives no
 * eye/nose landmarks. Blink and head-turn detection need landmarks, so this file loads
 * face-api.js ONLY for liveness (tiny detector + tiny 68-landmark model). Recognition
 * itself is still Member 3's.
 *
 * Setup (once): download these 4 files from
 *   https://github.com/justadudewhohacks/face-api.js/tree/master/weights
 * into a folder named  models/  in the project root:
 *   tiny_face_detector_model-weights_manifest.json   tiny_face_detector_model-shard1
 *   face_landmark_68_tiny_model-weights_manifest.json face_landmark_68_tiny_model-shard1
 *
 * Usage from js/login.js (Member 5), after Face.startCamera(video):
 *   await Liveness.load();
 *   const r = await Liveness.runServerChallenge(video, { onStatus: (text, level) => {...} });
 *   if (r.passed) { photo = Face.captureFrame(video); POST it to api/recognize.php }
 *   else          { show r.error }
 *
 * The page must contain <meta name="csrf-token" content="..."> (csrfMetaTag() in security.php).
 */
(function (root) {
    'use strict';

    const config = {
        challengeUrl: 'api/challenge.php',
        modelUrl: 'models',
        faceApiUrl: 'https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js',
        detector: { inputSize: 224, scoreThreshold: 0.5 },
        stepTimeoutMs: 15000,
        blink: {
            baselineFrames: 8,     // frames used to learn this person's "eyes open" EAR
            minBaseline: 0.16,     // below this the eyes are not clearly visible
            closedRatio: 0.72,     // EAR under baseline*0.72  = eye closed
            openRatio: 0.88        // EAR over  baseline*0.88  = eye open again (hysteresis)
        },
        turn: {
            baselineFrames: 6,
            maxNeutralOffset: 0.12, // must start roughly facing the camera
            turnDelta: 0.12,        // how far the nose must move (fraction of face width)
            holdFrames: 2,          // must stay turned for this many frames in a row
            invert: false           // set true if "right" and "left" come out swapped on your camera
        }
    };

    // ---------------------------------------------------------------- pure maths
    // Landmarks are the 68-point array: [{x, y}, ...]

    function dist(a, b) { return Math.hypot(a.x - b.x, a.y - b.y); }

    /** Eye aspect ratio from 6 eye points: (|p2-p6| + |p3-p5|) / (2*|p1-p4|). Open ~0.25-0.35, closed ~0.1. */
    function eyeAspectRatio(p) {
        const horizontal = 2 * dist(p[0], p[3]);
        if (horizontal === 0) return 0;
        return (dist(p[1], p[5]) + dist(p[2], p[4])) / horizontal;
    }

    function averageEAR(points) {
        return (eyeAspectRatio(points.slice(36, 42)) + eyeAspectRatio(points.slice(42, 48))) / 2;
    }

    /**
     * Where the nose tip sits between the two sides of the jaw: 0..1, about 0.5 when facing the camera.
     * Camera frames are NOT mirrored (only the on-screen preview is), and jaw point 0 is on the
     * image's left = the person's RIGHT. So turning to your own right makes this number SMALLER.
     */
    function yawRatio(points) {
        const left = points[0], right = points[16], nose = points[30];
        const width = right.x - left.x;
        return width === 0 ? 0.5 : (nose.x - left.x) / width;
    }

    function median(arr) {
        const s = arr.slice().sort((a, b) => a - b);
        return s[Math.floor(s.length / 2)];
    }

    // ---------------------------------------------------------------- detectors
    // update(points) -> { done: bool, hint?: string }

    class BlinkDetector {
        constructor(needed, cfg) {
            this.needed = needed; this.cfg = cfg;
            this.samples = []; this.baseline = null; this.closed = false; this.blinks = 0;
        }
        lostFace() { this.closed = false; }
        update(points) {
            const ear = averageEAR(points);
            if (this.baseline === null) {
                this.samples.push(ear);
                if (this.samples.length < this.cfg.baselineFrames) {
                    return { done: false, hint: 'Look at the camera with your eyes open.' };
                }
                const sorted = this.samples.slice().sort((a, b) => a - b);
                const candidate = sorted[Math.floor(sorted.length * 0.75)];   // upper part = eyes open
                if (candidate < this.cfg.minBaseline) {
                    this.samples = [];
                    return { done: false, hint: 'I cannot see your eyes clearly. Face the light.' };
                }
                this.baseline = candidate;
                return { done: false, hint: 'Now blink ' + this.needed + ' times.' };
            }
            const closedBelow = this.baseline * this.cfg.closedRatio;
            const openAbove = this.baseline * this.cfg.openRatio;
            if (!this.closed && ear < closedBelow) {
                this.closed = true;
            } else if (this.closed && ear > openAbove) {
                this.closed = false;
                this.blinks++;
            }
            return { done: this.blinks >= this.needed, blinks: this.blinks, ear: ear };
        }
    }

    class TurnDetector {
        /** direction: 'left' or 'right' = the PERSON's left/right. */
        constructor(direction, cfg) {
            this.direction = direction; this.cfg = cfg;
            this.samples = []; this.neutral = null; this.held = 0;
        }
        lostFace() { this.held = 0; }
        update(points) {
            const r = yawRatio(points);
            if (this.neutral === null) {
                if (Math.abs(r - 0.5) > this.cfg.maxNeutralOffset) {
                    this.samples = [];
                    return { done: false, hint: 'Look straight at the camera.' };
                }
                this.samples.push(r);
                if (this.samples.length < this.cfg.baselineFrames) {
                    return { done: false, hint: 'Look straight at the camera.' };
                }
                this.neutral = median(this.samples);
                return { done: false, hint: 'Slowly turn your head to your ' + this.direction.toUpperCase() + '.' };
            }
            let delta = (this.direction === 'right') ? (this.neutral - r) : (r - this.neutral);
            if (this.cfg.invert) delta = -delta;
            this.held = delta >= this.cfg.turnDelta ? this.held + 1 : 0;
            return { done: this.held >= this.cfg.holdFrames, ratio: r, delta: delta };
        }
    }

    function makeDetector(step, cfg) {
        if (step.type === 'blink') return new BlinkDetector(step.count || 2, cfg.blink);
        if (step.type === 'turn_left') return new TurnDetector('left', cfg.turn);
        if (step.type === 'turn_right') return new TurnDetector('right', cfg.turn);
        throw new Error('Unknown liveness step: ' + step.type);
    }

    // ---------------------------------------------------------------- face-api.js loading
    let loadPromise = null;

    function loadScript(src) {
        return new Promise((resolve, reject) => {
            const s = document.createElement('script');
            s.src = src; s.onload = resolve;
            s.onerror = () => reject(new Error('Could not load ' + src));
            document.head.appendChild(s);
        });
    }

    /** Loads face-api.js (if the page did not) and the two small models. Safe to call twice. */
    function load() {
        if (!loadPromise) {
            loadPromise = (async () => {
                if (!root.faceapi) await loadScript(config.faceApiUrl);
                await root.faceapi.nets.tinyFaceDetector.loadFromUri(config.modelUrl);
                await root.faceapi.nets.faceLandmark68TinyNet.loadFromUri(config.modelUrl);
            })().catch((err) => {
                loadPromise = null;
                throw new Error('Liveness models failed to load. Check the models/ folder. (' + err.message + ')');
            });
        }
        return loadPromise;
    }

    /** -> { status: 'none' | 'multiple' | 'ok', points?: [{x,y}] } */
    async function detectLandmarks(video) {
        const opts = new root.faceapi.TinyFaceDetectorOptions(config.detector);
        const faces = await root.faceapi.detectAllFaces(video, opts).withFaceLandmarks(true);
        if (faces.length === 0) return { status: 'none' };
        if (faces.length > 1) return { status: 'multiple' };
        return { status: 'ok', points: faces[0].landmarks.positions };
    }

    // ---------------------------------------------------------------- running steps
    const nextFrame = () => new Promise((r) => (root.requestAnimationFrame ? root.requestAnimationFrame(() => r()) : setTimeout(r, 30)));
    const now = () => (root.performance ? root.performance.now() : Date.now());

    function mergeConfig(extra) {
        return {
            stepTimeoutMs: (extra && extra.stepTimeoutMs) || config.stepTimeoutMs,
            blink: Object.assign({}, config.blink, extra && extra.blink),
            turn: Object.assign({}, config.turn, extra && extra.turn)
        };
    }

    async function runStep(video, step, getLandmarks, onStatus, cfg, signal) {
        const detector = makeDetector(step, cfg);
        const started = now();
        while (now() - started < cfg.stepTimeoutMs) {
            if (signal && signal.aborted) return { passed: false, reason: 'aborted' };
            const lm = await getLandmarks(video);
            if (lm.status === 'none') {
                detector.lostFace();
                onStatus('No face detected. Look at the camera.', 'warn');
            } else if (lm.status === 'multiple') {
                detector.lostFace();
                onStatus('More than one face detected. One person only.', 'warn');
            } else {
                const res = detector.update(lm.points);
                if (res.done) return { passed: true };
                if (res.hint) onStatus(res.hint, 'info');
            }
            await nextFrame();
        }
        return { passed: false, reason: 'timeout' };
    }

    /**
     * Runs the given steps in order.
     * @param steps e.g. [{type:'blink',count:2},{type:'turn_left'}]
     * @returns {Promise<{passed:boolean, steps_done:string[], reason?:string}>}
     */
    async function run(video, opts) {
        opts = opts || {};
        const onStatus = opts.onStatus || function () {};
        const getLandmarks = opts.getLandmarks || detectLandmarks;   // injectable for tests
        const cfg = mergeConfig(opts.config);
        const done = [];
        for (const step of opts.steps) {
            const r = await runStep(video, step, getLandmarks, onStatus, cfg, opts.signal);
            if (!r.passed) return { passed: false, steps_done: done, reason: r.reason };
            done.push(step.type);
            onStatus('Good!', 'ok');
        }
        return { passed: true, steps_done: done };
    }

    // ---------------------------------------------------------------- talking to the server
    async function postJson(url, body) {
        const meta = root.document && root.document.querySelector('meta[name="csrf-token"]');
        const res = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': meta ? meta.content : '' },
            body: JSON.stringify(body)
        });
        let data = null;
        try { data = await res.json(); } catch (e) { /* not JSON */ }
        return { status: res.status, data: data };
    }

    /**
     * The normal entry point: asks the server for a random challenge, performs it,
     * sends the result back for verification.
     * @returns {Promise<{passed:boolean, error?:string}>}
     */
    async function runServerChallenge(video, opts) {
        opts = opts || {};
        const onStatus = opts.onStatus || function () {};
        await load();

        const c = await postJson(config.challengeUrl, { action: 'new' });
        if (!c.data || !c.data.ok) {
            return { passed: false, error: (c.data && c.data.error) || 'Could not start the liveness check.' };
        }

        const result = await run(video, Object.assign({}, opts, { steps: c.data.steps }));
        if (!result.passed) {
            const why = result.reason === 'timeout' ? 'Liveness check timed out. Please try again.'
                      : result.reason === 'aborted' ? 'Liveness check cancelled.'
                      : 'Liveness check failed.';
            return { passed: false, error: why };
        }

        const v = await postJson(config.challengeUrl, {
            action: 'verify', token: c.data.token, result: { steps_done: result.steps_done }
        });
        if (!v.data || !v.data.ok) {
            return { passed: false, error: (v.data && v.data.error) || 'Liveness could not be verified.' };
        }
        onStatus('Liveness confirmed.', 'ok');
        return { passed: true };
    }

    const Liveness = {
        config: config,
        load: load,
        run: run,
        runServerChallenge: runServerChallenge,
        // exported for tests/test_liveness.js
        core: { eyeAspectRatio, averageEAR, yawRatio, BlinkDetector, TurnDetector }
    };

    root.Liveness = Liveness;
    if (typeof module !== 'undefined' && module.exports) module.exports = Liveness;
})(typeof window !== 'undefined' ? window : globalThis);
