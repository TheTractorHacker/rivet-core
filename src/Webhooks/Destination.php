<?php

declare(strict_types=1);

namespace RivetCore\Webhooks;

/**
 * A destination preset: everything an edition needs to render a "pick your platform" form and everything the dispatcher
 * needs to deliver to it (body format, method, default headers, allowed authentication). See Destinations.
 *
 * @api
 */
final readonly class Destination
{
    /**
     * @param 'automation'|'chat'|'notify'|'home'|'generic' $category
     * @param 'POST'|'PUT' $method
     * @param list<string> $authModes allowed from none, bearer, basic, header, hmac
     * @param list<DestinationField> $extraFields
     * @param array<string,string> $headers static default headers
     * @param list<string> $setupSteps
     * @param array<string,string> $verifySnippets language => code verifying the X-Rivet-Signature-V2 header (empty when the platform cannot verify)
     * @param list<string> $notes pitfalls and limits worth warning about
     * @param array<string,mixed> $formatOptions defaults passed to PayloadFormatter (e.g. template)
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $category,
        public string $description,
        public string $format,
        public string $method,
        public string $urlHint,
        public ?string $urlPattern,
        public array $authModes,
        public string $defaultAuth,
        public ?string $defaultAuthHeader,
        public array $extraFields,
        public array $headers,
        public string $docsUrl,
        public array $setupSteps,
        public string $sampleCurl,
        public array $verifySnippets,
        public array $notes,
        public array $formatOptions = [],
    ) {
    }

    /** Does a saved URL look right for this platform? Platforms without a fixed host accept any http(s) URL. */
    public function urlMatches(string $url): bool
    {
        return $this->urlPattern === null ? preg_match('#^https?://\S+$#i', $url) === 1 : preg_match($this->urlPattern, $url) === 1;
    }

    /**
     * Fill the {placeholders} of urlHint from the "url"-target fields (values are percent-encoded; ':' is kept, it is legal in a path segment and Telegram bot tokens need it). Placeholders with no
     * value (such as {txn}, which the dispatcher fills per message) are left in place.
     *
     * @param array<string,string> $values
     */
    public function buildUrl(array $values): string
    {
        $url = $this->urlHint;
        foreach ($this->extraFields as $f) {
            if ($f->target === 'url' && isset($values[$f->name]) && $values[$f->name] !== '') {
                $url = str_replace('{' . $f->name . '}', str_replace('%3A', ':', rawurlencode($values[$f->name])), $url);
            }
        }

        return $url;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $a = get_object_vars($this);
        $a['extraFields'] = array_map(static fn (DestinationField $f): array => $f->toArray(), $this->extraFields);

        return $a;
    }
}
