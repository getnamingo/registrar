Namingo Registrar custom email templates
=========================================

Files placed in this directory override the corresponding email templates
from ../templates/.

Email templates may use two formats:

    .txt   Plain-text email body and subject
    .html  HTML email body

For example, the bundled templates may contain:

    templates/wdrp.txt
    templates/wdrp.html

Custom versions can be placed here as:

    templates_custom/wdrp.txt
    templates_custom/wdrp.html

If a custom template exists and is readable, it will be used instead of
the corresponding bundled template.

If a custom .txt template exists but no custom .html template exists,
the message will be sent as plain text only. This preserves compatibility
with existing installations that customized text templates before HTML
email support was added.

If only a custom .html template exists, the bundled .txt template will
continue to be used as the plain-text alternative.

Only copy templates that you want to customize.

Files in this directory are local installation files and are preserved
by the Namingo Registrar upgrade process.