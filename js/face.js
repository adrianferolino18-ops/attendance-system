(function (global) {
    'use strict';

    const config = {
        detectUrl: 'api/face_detect.php',
        loopIntervalMs: 600,
        statusWidth: 480,
        statusQuality: 0.7
    };

    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));


    async function startCamera(video) {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            throw new Error('Camera is not available. Open the site using https://or http://localhost.');
        }

        let stream;
        try {
            stream = await navigator.mediaDevices.getUserMedia({
                video: {width: {ideal: 640}, height: {ideal:480}, facingMode: 'user'},
                audio: false
            });
        }catch (err) {
            if (err.name === 'NotAllowedError')
                throw new Error('Camcera permission was denied. Allow the camera and reload.');
            if (err.name === 'NotFoundError')
                throw new Error('No camera was found on this device.');
            if (err.name === 'NotReadableError')
                throw new Error('The camera is being used by another app.');
            throw new Error('Could not open the camera: ' + err.message);
        }

        video.srcObject = stream;
        video.muted = true;
        video.playsInline = true;
        await new Promise((resolve) => {
            if (video.readyState >= 2)
                return resolve();
            video.onloadeddata = () => resolve();
        });
        await video.play();
    }

    
    function stopCamera(video) {
        const stream = video && video.srcObject;
        if (stream) stream.getTracks().forEach((t) => t.stop());
        if (video) video.srcObject = null;
    }

    function captureFrame(video, maxWidth, quality) {
        if (!video || !video.videoWidth) 
            throw new Error('Camera is not ready yet.');

        const scale = maxWidth && video.videoWidth > maxWidth ? maxWidth / video.videoWidth : 1;
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(video.videoWidth * scale);
        canvas.height = Math.round(video.videoHeight * scale);
        canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
        
        return canvas.toDataURL('image/jpeg', quality || 0.85);
    }

    async function captureFrames(video, count, intervalMs, onProgress, opts) {
        count = count || 25;
        opts = opts || {};
        const timeoutMs = opts.timeoutMs || 60000;
        const started = performance.now();
        const frames = [];
        
        while (frames.length < count) {
            if (performance.now() - started > timeoutMs)
                break;
            if (opts.shouldStop && opts.shouldStop())
                break;

            const status = await detect(video);
            if(status.ok) {
                frames.push(captureFrame(video, 640, 0.85));
            }
            if (onProgress) {
                onProgress(frames.length, count, status);
            }
            await sleep(intervalMs || 300);
        }

        return frames;
    }

    async function detect(video) {
        try {
            const image = captureFrame(video, config.statusWidth, config.statusQuality);
            const res = await fetch(config.detectUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({image: image})
            });

            const data = await res.json();
            if (!data || typeof data.ok !== 'boolean')
                throw new Error('bad reply');

            return data;
        }catch (err) {
            return { ok: false, code: 'service_down', message: 'Face service is not reachable.', box: null, image_width: 0, image_height: 0};
        }
    }

    
    function drawOverlay(video, canvas, result, text, opts) {
        opts = opts || {};
        const W = video.videoWidth, H = video.videoHeight;

        if (!W || !H)
            return;
        if (canvas.width !== W)
            canvas.width = W;
        if (canvas.height !== H)
            canvas.height = H;

        const ctx = canvas.getContext('2d');
        ctx.clearRect(0, 0, W, H);

        const color = result.ok ? '#22c55e' : (result.code === 'no_face' ? '#f59e0b' : '#ef4444');

        if (result.box && result.image_width) {
            const k = W / result.image_width;
            let x = result.box[0] * k;
            const y = result.box[1] * k, w = result.box[2] * k, h = result.box[3] * k;

            if (opts.mirrored)
                x = W - x -w;
            ctx.lineWidth = 3;
            ctx.strokeStyle = color;
            ctx.strokeRect(x, y, w, h);
        }

        const label = text || result.message || '';
        
        if (label) {
            ctx.font = 'bold 18px sans-serif';
            const pad = 8;
            
            ctx.fillStyle = 'rgba(0,0,0,0.6)';
            ctx.fillRect(0, 0, ctx.measureText(label).width + pad * 2, 34);
            ctx.fillStyle = color;
            ctx.textBaseline = 'middle';
            ctx.fillText(label, pad, 17);
        }
    }


    function startLoop(video, canvas, cb, opts) {
        let running = true;
        (async function tick() {
            while (running) {
                const started = performance.now();
                const r = await detect(video);
                
                if (!running)
                    break;
                drawOverlay(video, canvas, r, null, opts);
                if (cb) 
                    cb(r);
                await sleep(Math.max(0, config.loopIntervalMs - (performance.now() - started)));
            }
        })();

        return function stop() {
            running = false;
            canvas.getContext('2d').clearRect(0, 0, canvas.width, canvas.height);
        };
    }

    global.Face = {
        config: config,
        startCamera: startCamera,
        stopCamera: stopCamera,
        captureFrame: captureFrame,
        captureFrames: captureFrames,
        detect: detect,
        drawOverlay: drawOverlay,
        startLoop: startLoop
    };

})(window);