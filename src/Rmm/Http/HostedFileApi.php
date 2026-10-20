<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Http;

use RivetCore\Rmm\Deploy\DeployProtocol;
use RivetCore\Rmm\Deploy\HostedFiles;
use RivetCore\Rmm\Settings\RmmSettings;

/**
 * `GET /api/v1/agent_software/{job_id}/{secret}`: the download of a HOSTED installer by the device that was given an install job for it. This is not part of the
 * frozen device protocol (no existing message, header or status changes); it is a new route the edition mounts next to the others. The secret is the job-bound grant
 * of {@see HostedFiles::grant()}; the agent sends no credentials when it downloads, so the address is the credential, it works only while the job is queued or
 * running, and every refusal (no such job, wrong secret, expired, finished, the file is gone or damaged, the module or `software_deploy` switched off) is the SAME
 * 404, so the route cannot be used to find out which jobs exist. TLS is required unless the edition allows plain http for loopback tests.
 *
 * @api
 */
final class HostedFileApi
{
    /**
     * @param \Closure(string,int,int):bool $rateLimit (bucket, limit, windowSeconds): true when within budget; the bucket is per client address
     */
    public function __construct(
        private readonly HostedFiles $files,
        private readonly RmmSettings $settings,
        private readonly \Closure $rateLimit,
        private readonly bool $allowInsecureHttp = false,
    ) {
    }

    public function handle(RmmRequest $req): RmmResponse
    {
        if (strtoupper($req->method) !== 'GET') {
            return RmmResponse::error(new ApiError(405, 'method_not_allowed', 'Use GET.', ['Allow' => 'GET']));
        }
        if (!$this->allowInsecureHttp && !$req->secureTransport) {
            return RmmResponse::error(new ApiError(426, 'tls_required', 'TLS is required.'));
        }
        if (!($this->rateLimit)('agent_software:' . $req->clientIp, 120, 60)) {
            return RmmResponse::error(new ApiError(429, 'rate_limited', 'Too many requests.', ['Retry-After' => '60']));
        }
        $notFound = RmmResponse::error(new ApiError(404, 'not_found', 'Not found.'));
        $s = $req->pathSegments;
        if (count($s) !== 2 || !$this->settings->featureOn(DeployProtocol::FEATURE)) {
            return $notFound;
        }
        $file = $this->files->open($s[0], $s[1]);

        return $file === null ? $notFound : FileDownload::response($file, 'installer.bin');
    }
}
