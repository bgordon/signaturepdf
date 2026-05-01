async function handleFileChange() {
    document.querySelector('#error_message').classList.add('d-none');
    document.querySelector('#card_resultat').classList.add('d-none');
    document.querySelector('#compressBtn').classList.add('btn-primary');
    document.querySelector('#compressBtn').classList.remove('btn-outline-primary');
    const fileInput = document.getElementById('input_pdf_upload');

    if(fileInput.files[0].size > maxSize) {
        alert("Le PDF ne doit pas dépasser " + convertOctet2MegoOctet(maxSize) + " Mo");
        fileInput.value = null;
        return;
    }

    const compressBtn = document.getElementById('compressBtn');
    const dropdownCompressBtn = document.getElementById('dropdownMenuReference');

    if (fileInput.files.length > 0) {
        compressBtn.closest('.btn-group').classList.remove('opacity-25');
        compressBtn.disabled = false;
        dropdownCompressBtn.disabled = false;
    } else {
        compressBtn.closest('.btn-group').classList.add('opacity-25');
        compressBtn.disabled = true;
        dropdownCompressBtn.disabled = true;
    }
}

function getProcessedFilename(originalName, suffix) {
    if (!originalName) {
        return 'document' + suffix + '.pdf';
    }

    return originalName.replace(/\.pdf$/i, '') + suffix + '.pdf';
}

async function setProcessedResult(blob, filename, isOcr) {
    document.querySelector('#card_resultat h6').classList.remove('d-none');
    document.querySelector('#compressBtn').classList.remove('btn-primary');
    document.querySelector('#compressBtn').classList.add('btn-outline-primary');
    document.querySelector('#uploaded_size').innerText = document.querySelector('#uploaded_size').dataset.templateText.replace("%s", convertOctet2MegoOctet(document.getElementById('input_pdf_upload').files[0].size));
    document.querySelector('#size_compressed').innerText = document.querySelector('#size_compressed').dataset.templateText.replace("%s", convertOctet2MegoOctet(blob.size));
    document.querySelector('#pourcentage_compressed').innerText = document.querySelector('#pourcentage_compressed').dataset.templateText.replace("%s", 100 - Math.round((blob.size * 100)/ document.getElementById('input_pdf_upload').files[0].size));
    document.querySelector('#card_resultat').classList.remove('d-none');

    if(isOcr) {
        document.querySelector('#card_resultat h6').classList.add('d-none');
        document.querySelector('#pourcentage_compressed').innerText = 'PDF with OCR';
    }

    let dataTransfer = new DataTransfer();
    dataTransfer.items.add(new File([blob], filename, {
        type: 'application/pdf'
    }));
    document.getElementById('input_pdf_compressed').files = dataTransfer.files;
    document.querySelector('#downloadBtn').focus();
}

async function handleBrowserProcessing(submitter) {
    var file = document.getElementById('input_pdf_upload').files[0];
    var blob = null;
    var filename = null;

    if (submitter.value === "ocr") {
        blob = await window.PDFBrowserProcessing.ocrPdfFile(file, processingCapabilities.language, {
            onProgress: function () {}
        });
        filename = getProcessedFilename(file.name, '_ocr');
        await setProcessedResult(blob, filename, true);
        return;
    }

    blob = await window.PDFBrowserProcessing.compressPdfFile(file, submitter.value, {
        onProgress: function () {}
    });
    filename = getProcessedFilename(file.name, '_compressed');
    await setProcessedResult(blob, filename, false);
}

document.addEventListener('DOMContentLoaded', function () {
    document.querySelector('#input_pdf_upload').addEventListener('change', function(e) {
        handleFileChange();
    })
    document.getElementById('form_compress').addEventListener('submit', async function(e) {
        document.querySelector('#error_message').classList.add('d-none');
        document.querySelector('#card_resultat').classList.add('d-none');
        document.querySelector('#compressBtn').classList.add('btn-primary');
        document.querySelector('#compressBtn').classList.remove('btn-outline-primary');
        startProcessingMode(document.getElementById('compressBtn'));
        if ((e.submitter.value === "ocr" && !processingCapabilities.serverOcr && processingCapabilities.browserOcr)
            || (e.submitter.value !== "ocr" && !processingCapabilities.serverCompression && processingCapabilities.browserCompression)) {
            try {
                await handleBrowserProcessing(e.submitter);
            } catch (error) {
                document.querySelector('#error_message').classList.remove('d-none');
                document.querySelector('#error_message').innerText = error && error.message ? error.message : 'Browser PDF processing failed';
            }
            endProcessingMode(document.getElementById('compressBtn'));
            e.preventDefault();
            return false;
        }
        const form = e.target;
        const formData = new FormData(form);
        formData.set(e.submitter.name, e.submitter.value);

        if(e.submitter.value == "ocr") {
            form.action = e.submitter.dataset.action;
        }

        fetch(form.action, { method: form.method, body: formData })
        .then(async function(response) {
          if (response.status == 204 ) {
              document.querySelector('#error_message').classList.remove('d-none');
              document.querySelector('#error_message').innerText = trad["Your pdf is already optimized"];
          } else if (response.ok) {
              let filename = decodeURI(response.headers.get('content-disposition').replace('attachment; filename=', ''));
              let blob = await response.blob();

              document.querySelector('#card_resultat h6').classList.remove('d-none');
              document.querySelector('#compressBtn').classList.remove('btn-primary');
              document.querySelector('#compressBtn').classList.add('btn-outline-primary');
              document.querySelector('#uploaded_size').innerText = document.querySelector('#uploaded_size').dataset.templateText.replace("%s", convertOctet2MegoOctet(document.getElementById('input_pdf_upload').files[0].size));
              document.querySelector('#size_compressed').innerText = document.querySelector('#size_compressed').dataset.templateText.replace("%s", convertOctet2MegoOctet(blob.size));
              document.querySelector('#pourcentage_compressed').innerText = document.querySelector('#pourcentage_compressed').dataset.templateText.replace("%s", 100 - Math.round((blob.size * 100)/ document.getElementById('input_pdf_upload').files[0].size));
              document.querySelector('#card_resultat').classList.remove('d-none');

              if(e.submitter.value == "ocr") {
                  document.querySelector('#card_resultat h6').classList.add('d-none');
                  document.querySelector('#pourcentage_compressed').innerText = 'PDF with OCR'
              }

              let dataTransfer = new DataTransfer();
              dataTransfer.items.add(new File([blob], filename, {
                  type: 'application/pdf'
              }));
              document.getElementById('input_pdf_compressed').files = dataTransfer.files;
              document.querySelector('#downloadBtn').focus();
          } else {
              document.querySelector('#error_message').classList.remove('d-none');
              document.querySelector('#error_message').innerText = await response.text();
              if(e.submitter.value == "ocr") {
                  document.querySelector('#error_message').innerText = "The PDF already contains selectable text"
              }
          }
          endProcessingMode(document.getElementById('compressBtn'));
        })

        e.preventDefault();
        return false;
    });

    document.getElementById('downloadBtn').addEventListener('click', async function(e) {
        await download(document.getElementById('input_pdf_compressed').files[0], document.getElementById('input_pdf_compressed').files[0].name);
    });
})
