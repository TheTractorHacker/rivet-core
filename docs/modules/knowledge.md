# Knowledge

`RivetCore\Knowledge\CredentialReferenceRenderer`: secure credential references in KB articles. An article may contain the literal
token `[[credential:123]]`; just before display, the (already purified) HTML has the token replaced by whatever the edition renders as a
"reveal" control.

## What it owns

No tables. The token is never rewritten in storage: the stored article and every version keep the raw text.

## You supply

A closure that returns the badge HTML for a credential id. The edition's reveal screen must re-check permissions itself: this class
never looks a credential up and never decrypts anything.

## Flags

None.

## Use it

<!-- run -->
```php
use RivetCore\Knowledge\CredentialReferenceRenderer;

$r = new CredentialReferenceRenderer(fn (int $id): string => '<a href="/credential/' . $id . '">reveal #' . $id . '</a>');
echo $r->render('<p>Wi-Fi password: [[credential:12]]</p><img alt="[[credential:13]]">'), "\n";
```

## How it fails

The token is substituted only in text. A token inside a tag or attribute is removed (never turned into markup), so a crafted article
cannot inject HTML through the badge. A token that does not parse is left alone.
