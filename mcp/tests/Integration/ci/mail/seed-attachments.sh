#!/bin/sh
# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Synthetic messages with attachments of alice for mail_read_attachment, stored in the folder Attachments of the
# throwaway IMAP server of a Mail cell through doveadm, the server's own tool. Called by mail-cell.sh before the Mail
# accounts exist; IMAP names the IMAP container. Every attachment is part 2 of a multipart/mixed message whose part 1
# is a short text body, except the S/MIME message, whose only part is the container (part 1):
# - CSV in ISO-8859-1, HTML with a script and a remote image, a one-page PDF, a DOCX declared as application/octet-stream,
#   a GIF, a PDF naming the unbounded LZWDecode filter, an embedded message/rfc822;
# - an S/MIME enveloped message (application/pkcs7-mime);
# - a message over the 1 MiB whole-message budget whose attachment is plain text.
#
# Usage: IMAP=<container> mail/seed-attachments.sh
set -eu

IMAP=${IMAP:?IMAP names the IMAP container}

PDF_B64='JVBERi0xLjQKMSAwIG9iago8PCAvVHlwZSAvQ2F0YWxvZyAvUGFnZXMgMiAwIFIgPj4KZW5kb2Jq\r\nCjIgMCBvYmoKPDwgL1R5cGUgL1BhZ2VzIC9LaWRzIFszIDAgUl0gL0NvdW50IDEgPj4KZW5kb2Jq\r\nCjMgMCBvYmoKPDwgL1R5cGUgL1BhZ2UgL1BhcmVudCAyIDAgUiAvTWVkaWFCb3ggWzAgMCAzMDAg\r\nMTQ0XSAvQ29udGVudHMgNCAwIFIgL1Jlc291cmNlcyA8PCAvRm9udCA8PCAvRjEgNSAwIFIgPj4g\r\nPj4gPj4KZW5kb2JqCjQgMCBvYmoKPDwgL0xlbmd0aCA0NiA+PgpzdHJlYW0KQlQgL0YxIDE4IFRm\r\nIDIwIDEwMCBUZCAoSW52b2ljZSA0MiBwYWlkKSBUaiBFVAplbmRzdHJlYW0KZW5kb2JqCjUgMCBv\r\nYmoKPDwgL1R5cGUgL0ZvbnQgL1N1YnR5cGUgL1R5cGUxIC9CYXNlRm9udCAvSGVsdmV0aWNhID4+\r\nCmVuZG9iagp4cmVmCjAgNgowMDAwMDAwMDAwIDY1NTM1IGYgCjAwMDAwMDAwMDkgMDAwMDAgbiAK\r\nMDAwMDAwMDA1OCAwMDAwMCBuIAowMDAwMDAwMTE1IDAwMDAwIG4gCjAwMDAwMDAyNDEgMDAwMDAg\r\nbiAKMDAwMDAwMDMzNyAwMDAwMCBuIAp0cmFpbGVyCjw8IC9TaXplIDYgL1Jvb3QgMSAwIFIgPj4K\r\nc3RhcnR4cmVmCjQwNwolJUVPRgo='
LZW_PDF_B64='JVBERi0xLjQKMSAwIG9iago8PCAvVHlwZSAvQ2F0YWxvZyAvUGFnZXMgMiAwIFIgPj4KZW5kb2Jq\r\nCjIgMCBvYmoKPDwgL1R5cGUgL1BhZ2VzIC9LaWRzIFszIDAgUl0gL0NvdW50IDEgPj4KZW5kb2Jq\r\nCjMgMCBvYmoKPDwgL1R5cGUgL1BhZ2UgL1BhcmVudCAyIDAgUiAvTWVkaWFCb3ggWzAgMCAzMDAg\r\nMTQ0XSAvQ29udGVudHMgNCAwIFIgL1Jlc291cmNlcyA8PCAvRm9udCA8PCAvRjEgNSAwIFIgPj4g\r\nPj4gPj4KZW5kb2JqCjQgMCBvYmoKPDwgL0xlbmd0aCAzMiA+PgpzdHJlYW0KQlQgL0YxIDE4IFRm\r\nIDIwIDEwMCBUZCAoeCkgVGogRVQKZW5kc3RyZWFtCmVuZG9iago1IDAgb2JqCjw8IC9UeXBlIC9G\r\nb250IC9TdWJ0eXBlIC9UeXBlMSAvQmFzZUZvbnQgL0hlbHZldGljYSA+PgplbmRvYmoKNiAwIG9i\r\nago8PCAvRmlsdGVyIC9MWldEZWNvZGUgL0xlbmd0aCAxID4+CnN0cmVhbQp4CmVuZHN0cmVhbQpl\r\nbmRvYmoKeHJlZgowIDcKMDAwMDAwMDAwMCA2NTUzNSBmIAowMDAwMDAwMDA5IDAwMDAwIG4gCjAw\r\nMDAwMDAwNTggMDAwMDAgbiAKMDAwMDAwMDExNSAwMDAwMCBuIAowMDAwMDAwMjQxIDAwMDAwIG4g\r\nCjAwMDAwMDAzMjMgMDAwMDAgbiAKMDAwMDAwMDM5MyAwMDAwMCBuIAp0cmFpbGVyCjw8IC9TaXpl\r\nIDcgL1Jvb3QgMSAwIFIgPj4Kc3RhcnR4cmVmCjQ2MgolJUVPRgo='
DOCX_B64='UEsDBBQAAAAIAFVrWV3muHRrXAAAAGIAAAATAAAAW0NvbnRlbnRfVHlwZXNdLnhtbBXMTQqAIBBA\r\n4auE+xxr0SJKL9EFRKYfylGcIer22fLxwZvcE6/mxsJHoll12ihnp+XNyE0V4lntInkE4LBj9KxT\r\nRqqyphK91CwbZB9OvyH0xgwQEgmStPI/FNgPUEsDBBQAAAAIAFVrWV2Y1JLDbgAAAKsAAAARAAAA\r\nd29yZC9kb2N1bWVudC54bWyzsa/IzVEoSy0qzszPs1Uy1DNQsrezKbdKyU8uzU3NK1EASucVW5Xb\r\nKpUrgcST8lMqQXQBiCgCESV2vqmpJZl56Qq5mXmlJanFNvogQRBZBCYL0NU7laakp5YoJBYUFOWX\r\npaZgUa8Ps0gf4RI7AFBLAQIUAxQAAAAIAFVrWV3muHRrXAAAAGIAAAATAAAAAAAAAAAAAACAAQAA\r\nAABbQ29udGVudF9UeXBlc10ueG1sUEsBAhQDFAAAAAgAVWtZXZjUksNuAAAAqwAAABEAAAAAAAAA\r\nAAAAAIABjQAAAHdvcmQvZG9jdW1lbnQueG1sUEsFBgAAAAACAAIAgAAAACoBAAAAAA=='
CSV_LATIN1_B64='bm9tZTtjaWRhZGUNCkpv4287U+NvIFBhdWxvDQo='
GIF_B64='R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'
SMIME_B64='AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8gISIjJCUmJygpKissLS4vMDEyMzQ1Njc4\r\nOTo7PD0+P0BBQkNERUZHSElKS0xNTk9QUVJTVFVWV1hZWltcXV5fYGFiY2RlZmdoaWprbG1ub3Bx\r\ncnN0dXZ3eHl6e3x9fn+AgYKDhIWGh4iJiouMjY6PkJGSk5SVlpeYmZqbnJ2en6ChoqOkpaanqKmq\r\nq6ytrq+wsbKztLW2t7i5uru8vb6/wMHCw8TFxsfIycrLzM3Oz9DR0tPU1dbX2Nna29zd3t/g4eLj\r\n5OXm5+jp6uvs7e7v8PHy8/T19vf4+fr7/P3+/wABAgMEBQYHCAkKCwwNDg8QERITFBUWFxgZGhsc\r\nHR4fICEiIyQlJicoKSorLC0uLzAxMjM0NTY3ODk6Ozw9Pj9AQUJDREVGR0hJSktMTU5PUFFSU1RV\r\nVldYWVpbXF1eX2BhYmNkZWZnaGlqa2xtbm9wcXJzdHV2d3h5ent8fX5/gIGCg4SFhoeIiYqLjI2O\r\nj5CRkpOUlZaXmJmam5ydnp+goaKjpKWmp6ipqqusra6vsLGys7S1tre4ubq7vL2+v8DBwsPExcbH\r\nyMnKy8zNzs/Q0dLT1NXW19jZ2tvc3d7f4OHi4+Tl5ufo6err7O3u7/Dx8vP09fb3+Pn6+/z9/v8='

# save_raw: stores the RFC 5322 message read from stdin in alice's Attachments folder.
save_raw() {
	docker exec -i "$IMAP" doveadm save -u alice -m Attachments
}

# with_attachment <subject> <part headers> <part body>: a message with a text body and one attachment.
with_attachment() {
	printf 'From: Carol <carol@example.com>\r\nTo: alice@example.com\r\nSubject: %s\r\nDate: Sat, 24 Oct 2026 09:00:00 +0000\r\nMessage-ID: <%s@mcp-it.example>\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary="att"\r\n\r\n' \
		"$1" "$(printf '%s' "$1" | tr ' ' '-')"
	printf -- '--att\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nSee the attachment.\r\n--att\r\n%b\r\n\r\n%b\r\n--att--\r\n' "$2" "$3"
}

# base64_part <type> <file name>: the headers of a base64 attachment.
base64_part() {
	printf 'Content-Type: %s; name="%s"\r\nContent-Disposition: attachment; filename="%s"\r\nContent-Transfer-Encoding: base64' "$1" "$2" "$2"
}

docker exec "$IMAP" doveadm mailbox create -u alice Attachments
with_attachment 'Attachment csv' "$(base64_part 'text/csv; charset=iso-8859-1' 'cidades.csv')" "$CSV_LATIN1_B64" | save_raw
with_attachment 'Attachment html' 'Content-Type: text/html; charset=utf-8; name="page.html"\r\nContent-Disposition: attachment; filename="page.html"' \
	'<html><body><p>Agenda of Friday</p><script>steal()</script><img src="https://tracker.example/a.gif"></body></html>' | save_raw
with_attachment 'Attachment pdf' "$(base64_part 'application/pdf' 'invoice.pdf')" "$PDF_B64" | save_raw
with_attachment 'Attachment docx' "$(base64_part 'application/octet-stream' 'minutes.bin')" "$DOCX_B64" | save_raw
with_attachment 'Attachment gif' "$(base64_part 'image/gif' 'pixel.gif')" "$GIF_B64" | save_raw
with_attachment 'Attachment lzw pdf' "$(base64_part 'application/pdf' 'packed.pdf')" "$LZW_PDF_B64" | save_raw
with_attachment 'Attachment embedded' 'Content-Type: message/rfc822; name="forwarded.eml"\r\nContent-Disposition: attachment; filename="forwarded.eml"' \
	'From: Dan <dan@example.com>\r\nTo: carol@example.com\r\nSubject: Inner\r\nMessage-ID: <inner@mcp-it.example>\r\n\r\nInner body.' | save_raw
printf 'From: Carol <carol@example.com>\r\nTo: alice@example.com\r\nSubject: Attachment smime\r\nDate: Sat, 24 Oct 2026 09:00:00 +0000\r\nMessage-ID: <attachment-smime@mcp-it.example>\r\nMIME-Version: 1.0\r\nContent-Type: application/pkcs7-mime; smime-type=enveloped-data; name="smime.p7m"\r\nContent-Disposition: attachment; filename="smime.p7m"\r\nContent-Transfer-Encoding: base64\r\n\r\n%b\r\n' "$SMIME_B64" | save_raw
with_attachment 'Attachment oversized' 'Content-Type: text/plain; charset=utf-8; name="big.txt"\r\nContent-Disposition: attachment; filename="big.txt"' \
	"$(awk 'BEGIN { line = sprintf("%075d", 0); gsub(/0/, "z", line); for (i = 0; i < 15000; i++) printf "%s\\r\\n", line }')" | save_raw
