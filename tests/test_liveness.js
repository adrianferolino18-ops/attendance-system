// Run from the project folder:  node tests/test_liveness.js
// Needs no camera and no browser: uses fake landmark data.
const assert = require('assert');
const Liveness = require('../js/liveness.js');
const { eyeAspectRatio, averageEAR, yawRatio, BlinkDetector, TurnDetector } = Liveness.core;

let pass = 0, fail = 0;
function check(label, fn) {
    try { fn(); console.log('PASS  ' + label); pass++; }
    catch (e) { console.log('FAIL  ' + label + '  -> ' + e.message); fail++; }
}
async function checkAsync(label, fn) {
    try { await fn(); console.log('PASS  ' + label); pass++; }
    catch (e) { console.log('FAIL  ' + label + '  -> ' + e.message); fail++; }
}

// ---- fake 68-point face --------------------------------------------------
// eyeHeight: 9 = open (EAR 0.30), 2 = closed (EAR 0.07).  noseRatio: 0.5 = facing camera.
function eye(x0, h) {
    return [
        { x: x0, y: 0 }, { x: x0 + 10, y: -h / 2 }, { x: x0 + 20, y: -h / 2 },
        { x: x0 + 30, y: 0 }, { x: x0 + 20, y: h / 2 }, { x: x0 + 10, y: h / 2 }
    ];
}
function face(eyeHeight, noseRatio) {
    const pts = Array.from({ length: 68 }, () => ({ x: 100, y: 100 }));
    pts[0] = { x: 0, y: 100 };
    pts[16] = { x: 200, y: 100 };
    pts[30] = { x: 200 * noseRatio, y: 100 };
    eye(60, eyeHeight).forEach((p, i) => (pts[36 + i] = p));
    eye(110, eyeHeight).forEach((p, i) => (pts[42 + i] = p));
    return pts;
}
const noise = () => (Math.random() - 0.5) * 0.4;   // +/-0.2 px jitter on eye height

// ---- maths ---------------------------------------------------------------
check('EAR of open eye is about 0.30', () => assert(Math.abs(averageEAR(face(9, 0.5)) - 0.3) < 0.01));
check('EAR of closed eye is about 0.07', () => assert(Math.abs(averageEAR(face(2, 0.5)) - 0.0667) < 0.01));
check('EAR handles zero-width eye without crashing', () => assert.strictEqual(eyeAspectRatio(Array(6).fill({ x: 1, y: 1 })), 0));
check('yaw ratio is 0.5 facing camera', () => assert.strictEqual(yawRatio(face(9, 0.5)), 0.5));
check('yaw ratio drops when nose moves toward jaw point 0', () => assert(yawRatio(face(9, 0.3)) < 0.5));

// ---- BlinkDetector -------------------------------------------------------
check('counts 2 real blinks', () => {
    const d = new BlinkDetector(2, Liveness.config.blink);
    for (let i = 0; i < 10; i++) d.update(face(9 + noise(), 0.5));      // baseline
    const seq = [9, 9, 2, 2, 9, 9, 9, 2, 9, 9];                         // two blinks
    let done = false;
    seq.forEach((h) => { done = d.update(face(h, 0.5)).done; });
    assert(done, 'expected done after 2 blinks, got blinks=' + d.blinks);
});
check('one blink is not enough for 2', () => {
    const d = new BlinkDetector(2, Liveness.config.blink);
    for (let i = 0; i < 10; i++) d.update(face(9, 0.5));
    [9, 2, 2, 9, 9, 9].forEach((h) => d.update(face(h, 0.5)));
    assert.strictEqual(d.blinks, 1);
});
check('printed photo / screenshot (eyes never change, with sensor noise) = 0 blinks', () => {
    const d = new BlinkDetector(2, Liveness.config.blink);
    for (let i = 0; i < 300; i++) d.update(face(9 + noise(), 0.5));
    assert.strictEqual(d.blinks, 0);
});
check('works for a person with narrower eyes (EAR 0.20 open)', () => {
    const d = new BlinkDetector(1, Liveness.config.blink);
    for (let i = 0; i < 10; i++) d.update(face(6, 0.5));                // 6/30 = 0.20
    [6, 1.5, 1.5, 6, 6].forEach((h) => d.update(face(h, 0.5)));
    assert.strictEqual(d.blinks, 1);
});
check('eyes not visible (EAR 0.05 baseline) is rejected, not accepted', () => {
    const d = new BlinkDetector(1, Liveness.config.blink);
    let last;
    for (let i = 0; i < 10; i++) last = d.update(face(1.5, 0.5));
    assert.strictEqual(d.baseline, null);
    assert(/eyes/i.test(last.hint));
});

// ---- TurnDetector --------------------------------------------------------
function runTurn(direction, ratios) {
    const d = new TurnDetector(direction, Liveness.config.turn);
    for (let i = 0; i < 8; i++) d.update(face(9, 0.5));                 // neutral
    let done = false;
    ratios.forEach((r) => { done = d.update(face(9, r)).done || done; });
    return done;
}
check('turn right (ratio falls) passes "turn_right"', () => assert(runTurn('right', [0.5, 0.42, 0.34, 0.33, 0.33])));
check('turn left (ratio rises) passes "turn_left"', () => assert(runTurn('left', [0.5, 0.58, 0.66, 0.67, 0.67])));
check('turning the wrong way does NOT pass', () => {
    assert(!runTurn('right', [0.5, 0.58, 0.66, 0.67, 0.67]));
    assert(!runTurn('left', [0.5, 0.42, 0.34, 0.33, 0.33]));
});
check('tiny head wobble does NOT pass', () => assert(!runTurn('right', [0.5, 0.47, 0.49, 0.46, 0.48])));
check('a single-frame spike does NOT pass (holdFrames=2)', () => assert(!runTurn('right', [0.5, 0.3, 0.5, 0.5])));
check('refuses to start while face is already turned away', () => {
    const d = new TurnDetector('right', Liveness.config.turn);
    for (let i = 0; i < 10; i++) d.update(face(9, 0.25));
    assert.strictEqual(d.neutral, null);
});
check('config.turn.invert swaps the direction', () => {
    const d = new TurnDetector('right', Object.assign({}, Liveness.config.turn, { invert: true }));
    for (let i = 0; i < 8; i++) d.update(face(9, 0.5));
    let done = false;
    [0.58, 0.66, 0.67, 0.67].forEach((r) => { done = d.update(face(9, r)).done || done; });
    assert(done);
});

// ---- full run() loop with a fake camera ----------------------------------
function fakeCamera(frames) {          // returns a getLandmarks() that plays the frames, then repeats the last
    let i = 0;
    return async () => frames[Math.min(i++, frames.length - 1)];
}
const ok = (h, r) => ({ status: 'ok', points: face(h, r) });
const fast = { config: { stepTimeoutMs: 1500 } };

(async () => {
    const steps = [{ type: 'blink', count: 2 }, { type: 'turn_left' }];

    await checkAsync('live person: blink twice then turn left -> passes both steps', async () => {
        const frames = [];
        for (let i = 0; i < 10; i++) frames.push(ok(9, 0.5));
        [2, 2, 9, 9, 2, 9, 9].forEach((h) => frames.push(ok(h, 0.5)));
        for (let i = 0; i < 8; i++) frames.push(ok(9, 0.5));
        [0.58, 0.66, 0.67, 0.67, 0.67].forEach((r) => frames.push(ok(9, r)));
        const r = await Liveness.run({}, Object.assign({ steps, getLandmarks: fakeCamera(frames) }, fast));
        assert(r.passed, 'reason=' + r.reason + ' done=' + r.steps_done);
        assert.deepStrictEqual(r.steps_done, ['blink', 'turn_left']);
    });

    await checkAsync('photo held to camera: never blinks -> fails with timeout', async () => {
        const r = await Liveness.run({}, Object.assign({ steps, getLandmarks: async () => ok(9 + noise(), 0.5) }, { config: { stepTimeoutMs: 400 } }));
        assert(!r.passed);
        assert.strictEqual(r.reason, 'timeout');
        assert.deepStrictEqual(r.steps_done, []);
    });

    await checkAsync('two faces in frame -> fails and reports it', async () => {
        const msgs = [];
        const r = await Liveness.run({}, {
            steps, getLandmarks: async () => ({ status: 'multiple' }),
            onStatus: (t) => msgs.push(t), config: { stepTimeoutMs: 300 }
        });
        assert(!r.passed);
        assert(msgs.some((m) => /more than one/i.test(m)));
    });

    await checkAsync('no face -> fails and reports it', async () => {
        const msgs = [];
        const r = await Liveness.run({}, {
            steps, getLandmarks: async () => ({ status: 'none' }),
            onStatus: (t) => msgs.push(t), config: { stepTimeoutMs: 300 }
        });
        assert(!r.passed);
        assert(msgs.some((m) => /no face/i.test(m)));
    });

    await checkAsync('abort signal stops the check', async () => {
        const r = await Liveness.run({}, { steps, getLandmarks: async () => ok(9, 0.5), signal: { aborted: true } });
        assert.strictEqual(r.reason, 'aborted');
    });

    console.log('\n' + pass + ' passed, ' + fail + ' failed');
    process.exit(fail ? 1 : 0);
})();
