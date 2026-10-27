# Knowledge

Namespace `RivetCore\Knowledge`. One class, `CredentialReferenceRenderer`, which lets a knowledge-base article point at a stored credential without ever containing the secret.

## Overview

- Owns no tables and has no migration.
- Convention: an article body may contain the literal token `[[credential:123]]`, where `123` is a credential id (case-insensitive, digits only). When an article's already-purified HTML is about to be displayed, the renderer swaps each token for whatever the edition renders as a "reveal" control.
- The renderer never looks the credential up, never decrypts anything, and never rewrites storage. The stored article and every version snapshot keep the raw token text; only the display copy changes.

## Contracts an edition must implement

A single closure given to the constructor: `\Closure(int): string`, which receives a credential id and returns the HTML for the reveal control (a button or link).

The edition is responsible for what the control does. The reveal screen it opens must re-check, on its own, that the current user may see that credential (module permission and the credential's client scope). This class has no opinion on who may see a secret, and a token in an article must never be treated as authorization.

## Key classes

```php
use RivetCore\Knowledge\CredentialReferenceRenderer;

$renderer = new CredentialReferenceRenderer(
    static fn (int $id): string => '<button class="reveal" data-id="' . $id . '">Reveal</button>'
);

$renderer->containsReference('<p>none</p>');   // false: cheap check before rendering
echo $renderer->render('<p>Login [[credential:12]] <a title="[[credential:5]]">x</a> [[credential:abc]]</p>');
// <p>Login <button class="reveal" data-id="12">Reveal</button> <a title="">x</a> [[credential:abc]]</p>
```

- `render(string $html): string` splits the HTML into tags and text. A token in text becomes the badge; a token inside a tag (an attribute value, a tag name) is removed instead, so badge markup can never break out of an attribute and change the DOM. Quoted attribute values containing `>` stay inside their tag. Anything that is not a well-formed token (`[[credential:abc]]`) is left as it is.
- `containsReference(string $html): bool` tells whether a token is present.
- The class is not `final`: an edition subclasses it to pin its own badge in the constructor, which is how both editions use it.

## Configuration

None.

## How it fails

- Never throws on content. If the HTML cannot be split, `render()` returns its input unchanged. The badge closure is the edition's code; an exception from it propagates.
- A token for a credential that does not exist, or that the viewer may not see, still renders the badge: the lookup and permission check happen when the viewer activates it, in the edition's own screen.

## Security notes

- Render only HTML that has already been purified. This class is a display-time substitution, not a sanitiser, and the badge is trusted markup from the edition.
- The badge closure receives an integer, so the id cannot carry markup; keep it that way when building the control (cast or escape anything else you add).
- Keep secrets out of article text. The token is the only supported way to reference one; the edition's reveal endpoint is where access control, audit and decryption belong.
- Do not run the renderer on text that is edited and saved back: that would write the badge into storage. Render a display copy only.

## Used by

- RivetIT (`/var/www/mw-itflow.foleyit.com`): `src/Knowledge/CredentialReferenceRenderer.php` (namespace `ITFlow\Knowledge`) extends the Core class with a reveal link that opens `modals/credential/credential_view.php`, which re-checks permission. It is applied in `agent/kb_article.php` to the displayed article. The portal page `client/kb_article.php` does not use the renderer: it drops the tokens itself because the portal has no reveal modal.
- RivetMSP (`/home/sysadmin/rivetmsp-beta`): `src/Knowledge/CredentialReferenceRenderer.php` (namespace `RivetMSP\Knowledge`), applied in `agent/kb_article.php`.

## Links

- [CHANGELOG](../../CHANGELOG.md): 0.6.0 (module introduced), 0.18.1 (a token inside a tag or attribute is removed, not substituted).
- [KB converters](kb.md) (create the articles), [Writing an edition adapter](../adapters.md), [modules overview](README.md).
- Tests: `tests/Unit/KbAndKnowledgeTest.php`.
