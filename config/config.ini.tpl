[globals]

; Path to which stored pdf to activate the mode of sharing a signature to several.
; To deactivate this mode, simply do not configure it or leave it empty
PDF_STORAGE_PATH=${PDF_STORAGE_PATH}

; Disable organization tab and routes
DISABLE_ORGANIZATION=${DISABLE_ORGANIZATION}

; Manage demo link pdf : true (by default, show), false (hide), or custom link
PDF_DEMO_LINK=${PDF_DEMO_LINK}

; Encryption activation (default activation if PHP OpenSSL is available)
PDF_STORAGE_ENCRYPTION=${PDF_STORAGE_ENCRYPTION}

; Legacy NSS3 configuration (optional)
NSS3_DIRECTORY=${NSS3_DIRECTORY}
NSS3_PASSWORD=${NSS3_PASSWORD}
NSS3_NICK=${NSS3_NICK}

; Pure PHP/OpenSSL certificate signing (optional)
SIGN_CERTIFICATE_FILE=${SIGN_CERTIFICATE_FILE}
SIGN_PRIVATE_KEY_FILE=${SIGN_PRIVATE_KEY_FILE}
SIGN_PRIVATE_KEY_PASSWORD=${SIGN_PRIVATE_KEY_PASSWORD}
SIGN_EXTRA_CERTIFICATES_FILE=${SIGN_EXTRA_CERTIFICATES_FILE}
SIGN_CERTIFICATE_NAME=${SIGN_CERTIFICATE_NAME}
SIGN_CERTIFICATE_LOCATION=${SIGN_CERTIFICATE_LOCATION}
SIGN_CERTIFICATE_CONTACT_INFO=${SIGN_CERTIFICATE_CONTACT_INFO}
