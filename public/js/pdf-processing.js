(function (window) {
    function initialiseBrowserProcessing() {
        if (window.PDFBrowserProcessing) {
            return;
        }

        if (!window.PDFLib || !window.pdfjsLib) {
            return;
        }

        var OCR_LANGUAGE_MAP = {
            ar: 'ara',
            de: 'deu',
            en: 'eng',
            es: 'spa',
            eu: 'spa',
            fr: 'fra',
            gl: 'spa',
            it: 'ita',
            kab: 'fra',
            nl: 'nld',
            oc: 'fra',
            pl: 'pol',
            ro: 'ron',
            ta: 'tam',
            tr: 'tur'
        };

        function loadScript(src) {
            return new Promise(function (resolve, reject) {
                var existing = document.querySelector('script[data-src="' + src + '"]');
                if (existing) {
                    if (existing.dataset.loaded === 'true') {
                        resolve();
                        return;
                    }
                    existing.addEventListener('load', function () { resolve(); }, { once: true });
                    existing.addEventListener('error', function () { reject(new Error('Unable to load script ' + src)); }, { once: true });
                    return;
                }

                var script = document.createElement('script');
                script.src = src;
                script.async = true;
                script.dataset.src = src;
                script.addEventListener('load', function () {
                    script.dataset.loaded = 'true';
                    resolve();
                }, { once: true });
                script.addEventListener('error', function () {
                    reject(new Error('Unable to load script ' + src));
                }, { once: true });
                document.head.appendChild(script);
            });
        }

        function getTesseractLanguage(language) {
            if (!language) {
                return 'eng';
            }

            return OCR_LANGUAGE_MAP[language] || 'eng';
        }

        function canvasToBlob(canvas, type, quality) {
            return new Promise(function (resolve, reject) {
                canvas.toBlob(function (blob) {
                    if (!blob) {
                        reject(new Error('Unable to create a blob from canvas'));
                        return;
                    }
                    resolve(blob);
                }, type, quality);
            });
        }

        function blobToUint8Array(blob) {
            return blob.arrayBuffer().then(function (arrayBuffer) {
                return new Uint8Array(arrayBuffer);
            });
        }

        function getCompressionPreset(preset) {
            if (preset === 'low') {
                return { scale: 1.4, quality: 0.82 };
            }
            if (preset === 'high') {
                return { scale: 0.95, quality: 0.55 };
            }

            return { scale: 1.15, quality: 0.68 };
        }

        async function renderPdfPages(file, options) {
            var arrayBuffer = await file.arrayBuffer();
            var loadingTask = window.pdfjsLib.getDocument({ data: arrayBuffer });
            var pdf = await loadingTask.promise;
            var renderedPages = [];
            var scale = options && options.scale ? options.scale : 1.2;

            for (var pageNumber = 1; pageNumber <= pdf.numPages; pageNumber++) {
                if (options && typeof options.onProgress === 'function') {
                    options.onProgress({ step: 'render', page: pageNumber, total: pdf.numPages });
                }
                var page = await pdf.getPage(pageNumber);
                var viewport = page.getViewport({ scale: scale });
                var canvas = document.createElement('canvas');
                var context = canvas.getContext('2d', { alpha: false });
                canvas.width = Math.ceil(viewport.width);
                canvas.height = Math.ceil(viewport.height);
                await page.render({
                    canvasContext: context,
                    viewport: viewport
                }).promise;
                renderedPages.push({
                    pageNumber: pageNumber,
                    canvas: canvas,
                    width: canvas.width,
                    height: canvas.height
                });
            }

            return renderedPages;
        }

        async function buildPdfFromRenderedPages(renderedPages, options) {
            var pdfDoc = await window.PDFLib.PDFDocument.create();
            var font = null;
            if (options && options.includeTextLayer) {
                font = await pdfDoc.embedFont(window.PDFLib.StandardFonts.Helvetica);
            }

            for (var pageIndex = 0; pageIndex < renderedPages.length; pageIndex++) {
                var renderedPage = renderedPages[pageIndex];
                var imageBlob = await canvasToBlob(renderedPage.canvas, 'image/jpeg', options && options.quality ? options.quality : 0.75);
                var imageBytes = await blobToUint8Array(imageBlob);
                var image = await pdfDoc.embedJpg(imageBytes);
                var pdfPage = pdfDoc.addPage([renderedPage.width, renderedPage.height]);

                pdfPage.drawImage(image, {
                    x: 0,
                    y: 0,
                    width: renderedPage.width,
                    height: renderedPage.height
                });

                if (font && renderedPage.words && renderedPage.words.length) {
                    for (var wordIndex = 0; wordIndex < renderedPage.words.length; wordIndex++) {
                        var word = renderedPage.words[wordIndex];
                        if (!word.text || !word.bbox) {
                            continue;
                        }

                        var width = Math.max(1, (word.bbox.x1 || 0) - (word.bbox.x0 || 0));
                        var height = Math.max(1, (word.bbox.y1 || 0) - (word.bbox.y0 || 0));
                        var fontSize = Math.max(6, height * 0.8);
                        var y = renderedPage.height - (word.bbox.y1 || 0);

                        pdfPage.drawText(word.text, {
                            x: Math.max(0, word.bbox.x0 || 0),
                            y: Math.max(0, y),
                            size: fontSize,
                            font: font,
                            maxWidth: width,
                            lineHeight: fontSize,
                            color: window.PDFLib.rgb(0, 0, 0),
                            opacity: 0.01
                        });
                    }
                }
            }

            var pdfBytes = await pdfDoc.save({ useObjectStreams: false });

            return new Blob([pdfBytes], { type: 'application/pdf' });
        }

        async function ensureTesseract() {
            if (window.Tesseract) {
                return window.Tesseract;
            }

            await loadScript('https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js');

            if (!window.Tesseract) {
                throw new Error('Tesseract.js unavailable');
            }

            return window.Tesseract;
        }

        async function compressPdfFile(file, preset, options) {
            var compressionPreset = getCompressionPreset(preset);
            var renderedPages = await renderPdfPages(file, {
                scale: compressionPreset.scale,
                onProgress: options && options.onProgress ? options.onProgress : null
            });

            return buildPdfFromRenderedPages(renderedPages, {
                quality: compressionPreset.quality
            });
        }

        async function ocrPdfFile(file, language, options) {
            var Tesseract = await ensureTesseract();
            var renderedPages = await renderPdfPages(file, {
                scale: 1.5,
                onProgress: options && options.onProgress ? options.onProgress : null
            });
            var worker = await Tesseract.createWorker(getTesseractLanguage(language), 1, {
                logger: function (message) {
                    if (options && typeof options.onProgress === 'function') {
                        options.onProgress({ step: 'ocr', detail: message });
                    }
                }
            });

            try {
                for (var pageIndex = 0; pageIndex < renderedPages.length; pageIndex++) {
                    if (options && typeof options.onProgress === 'function') {
                        options.onProgress({ step: 'recognize', page: pageIndex + 1, total: renderedPages.length });
                    }
                    var dataUrl = renderedPages[pageIndex].canvas.toDataURL('image/png');
                    var result = await worker.recognize(dataUrl);
                    renderedPages[pageIndex].words = result && result.data && result.data.words ? result.data.words : [];
                }
            } finally {
                await worker.terminate();
            }

            return buildPdfFromRenderedPages(renderedPages, {
                includeTextLayer: true,
                quality: 0.86
            });
        }

        window.PDFBrowserProcessing = {
            compressPdfFile: compressPdfFile,
            ocrPdfFile: ocrPdfFile,
            getTesseractLanguage: getTesseractLanguage
        };
    }

    initialiseBrowserProcessing();
    window.addEventListener('pdfjs-ready', initialiseBrowserProcessing);
    document.addEventListener('DOMContentLoaded', initialiseBrowserProcessing);
})(window);
