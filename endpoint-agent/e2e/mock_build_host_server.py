#!/usr/bin/env python3
"""Mock of the four build host endpoints (docs/rmm/BUILD_HOST_PROTOCOL.md) with strict validation.

Test tool only: plain http on 127.0.0.1. Endpoints (POST, JSON): /poll /status /upload /complete.
GET /_state returns what happened (no auth; tests only). Standard library only.
"""
import argparse, base64, binascii, hashlib, hmac, json, os, re, sys, threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

NAMES = ["rivetit-agent-windows-amd64.exe", "rivetit-agent-windows-arm64.exe",
         "rivetit-agent-windows-amd64.msi", "SHA256SUMS"]
STATES = {"building", "uploading", "failed"}
MAX_BODY = 2 * 1024 * 1024          # PHP default post_max_size is small; the protocol must fit
MAX_CHUNK = 1024 * 1024
MAX_FILE = 64 * 1024 * 1024
VERSION_RE = re.compile(r"^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$")


class Err(Exception):
    def __init__(self, code, msg):
        super().__init__(msg); self.code, self.msg = code, msg


class Mock:
    def __init__(self, a):
        self.a, self.lock = a, threading.Lock()
        self.job = None
        if a.job_id:
            self.job = {"id": a.job_id, "version": a.version, "source_ref": a.ref,
                        "targets": a.targets.split(), "msi": a.msi, "expires_at": "2099-01-01T00:00:00Z"}
        self.state = "queued" if self.job else "none"
        self.chunks, self.totals, self.files = {}, {}, {}
        self.events, self.errors, self.statuses = [], [], []
        self.upload_calls = 0
        self.failed_log_tail = None
        self.manifest = None

    def snapshot(self):
        return {"state": self.state, "events": self.events, "errors": self.errors, "statuses": self.statuses,
                "uploaded": {n: {"size": len(d), "sha256": hashlib.sha256(d).hexdigest(), "chunks": self.totals.get(n)}
                             for n, d in self.files.items()},
                "failed_log_tail": self.failed_log_tail, "manifest": self.manifest,
                "upload_calls": self.upload_calls}

    # ---- helpers
    def need(self, cond, msg, code=400):
        if not cond:
            raise Err(code, msg)

    def check_job(self, body):
        self.need(self.job is not None and body.get("job_id") == self.job["id"], "unknown job_id", 404)
        self.need(self.state in ("claimed", "building", "uploading"), "job is %s" % self.state, 409)

    def poll(self, b):
        self.need(set(b) == {"host_version", "capabilities"}, "poll: unexpected members")
        self.need(isinstance(b["host_version"], str) and 0 < len(b["host_version"]) <= 32, "host_version")
        c = b["capabilities"]
        self.need(isinstance(c, dict) and set(c) == {"docker", "msi"} and all(isinstance(v, bool) for v in c.values()), "capabilities")
        if self.job and self.state == "queued" and c["docker"]:
            self.state = "claimed"; self.events.append("claimed")
            return {"job": self.job}
        return {"job": None}

    def status(self, b):
        self.need(set(b) <= {"job_id", "state", "note", "log_tail"} and {"job_id", "state", "note"} <= set(b), "status: members")
        self.check_job(b)
        self.need(b["state"] in STATES, "status: bad state")
        self.need(isinstance(b["note"], str) and len(b["note"]) <= 500, "status: note too long")
        if "log_tail" in b:
            self.need(b["state"] == "failed", "log_tail only with failed")
            self.need(isinstance(b["log_tail"], str) and len(b["log_tail"].encode()) <= 4096, "log_tail too long")
        self.statuses.append({"state": b["state"], "note": b["note"]})
        if b["state"] == "failed":
            self.state = "failed"; self.failed_log_tail = b.get("log_tail"); self.events.append("failed")
        else:
            self.state = b["state"]
        return {"ok": True}

    def upload(self, b):
        self.upload_calls += 1
        if self.a.fail_uploads and self.upload_calls <= self.a.fail_uploads:
            raise Err(503, "injected upload failure")
        self.need(set(b) == {"job_id", "name", "index", "total", "sha256_chunk", "data_b64"}, "upload: members")
        self.check_job(b)
        n, i, t = b["name"], b["index"], b["total"]
        self.need(n in NAMES, "upload: name not allowed")
        self.need(self.job and (n == "SHA256SUMS" or self.allowed_name(n)), "upload: file not requested by the job")
        self.need(type(i) is int and type(t) is int and 1 <= t <= 64 and 0 <= i < t, "upload: index/total")
        self.need(isinstance(b["data_b64"], str) and isinstance(b["sha256_chunk"], str), "upload: types")
        try:
            raw = base64.b64decode(b["data_b64"], validate=True)
        except (binascii.Error, ValueError):
            raise Err(400, "upload: bad base64")
        self.need(0 < len(raw) <= MAX_CHUNK, "upload: chunk size")
        self.need(re.fullmatch(r"[0-9a-f]{64}", b["sha256_chunk"]) and
                  hmac.compare_digest(hashlib.sha256(raw).hexdigest(), b["sha256_chunk"]), "upload: chunk hash mismatch")
        self.need(self.totals.setdefault(n, t) == t, "upload: total changed")
        self.chunks.setdefault(n, {})[i] = raw
        if len(self.chunks[n]) == t:
            data = b"".join(self.chunks[n][k] for k in range(t))
            self.need(len(data) <= MAX_FILE, "upload: file too big", 413)
            self.files[n] = data
        return {"ok": True, "received": len(self.chunks[n])}

    def allowed_name(self, n):
        t = self.job["targets"]
        return (n.endswith("amd64.exe") and "windows-amd64" in t) or (n.endswith("arm64.exe") and "windows-arm64" in t) \
            or (n.endswith(".msi") and self.job["msi"])

    def complete(self, b):
        self.need(set(b) == {"job_id", "manifest"}, "complete: members")
        self.check_job(b)
        m = b["manifest"]
        self.need(isinstance(m, dict) and set(m) == {"files", "commit", "go_version", "built_at"}, "manifest members")
        self.need(isinstance(m["commit"], str) and re.fullmatch(r"[0-9a-f]{40}", m["commit"]), "manifest commit")
        self.need(isinstance(m["go_version"], str) and m["go_version"], "manifest go_version")
        self.need(isinstance(m["built_at"], str) and re.fullmatch(r"\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ", m["built_at"]), "manifest built_at")
        files = m["files"]
        self.need(isinstance(files, dict) and files, "manifest files")
        self.need("SHA256SUMS" in self.files, "SHA256SUMS was not uploaded")
        sums = {}
        for line in self.files["SHA256SUMS"].decode("utf-8", "replace").splitlines():
            mm = re.fullmatch(r"([0-9a-f]{64})  (\S+)", line)
            self.need(mm, "SHA256SUMS line format"); sums[mm.group(2)] = mm.group(1)
        self.need(set(files) == set(sums), "manifest and SHA256SUMS list different files")
        for name, sha in files.items():
            self.need(name in NAMES and name != "SHA256SUMS" and name in self.files, "file not uploaded: %s" % name)
            actual = hashlib.sha256(self.files[name]).hexdigest()
            self.need(actual == sha == sums[name], "hash mismatch for %s" % name)
        for name in self.files:
            self.need(name == "SHA256SUMS" or name in files, "uploaded but not in manifest: %s" % name)
        if self.a.out_dir:
            os.makedirs(self.a.out_dir, exist_ok=True)
            for n, d in self.files.items():
                with open(os.path.join(self.a.out_dir, n), "wb") as f:
                    f.write(d)
        self.manifest = m; self.state = "built"; self.events.append("built")
        return {"ok": True, "state": "built"}


def make_handler(mock):
    class H(BaseHTTPRequestHandler):
        protocol_version = "HTTP/1.1"

        def log_message(self, *a):
            pass

        def send(self, code, obj):
            data = json.dumps(obj).encode()
            self.send_response(code)
            self.send_header("Content-Type", "application/json"); self.send_header("Content-Length", str(len(data)))
            self.end_headers(); self.wfile.write(data)

        def do_GET(self):
            if self.path == "/_state":
                with mock.lock:
                    return self.send(200, mock.snapshot())
            self.send(404, {"error": "not found"})

        def do_POST(self):
            try:
                n = int(self.headers.get("Content-Length", "-1"))
                if n < 0 or n > MAX_BODY:
                    self.rfile.read(max(0, min(n, MAX_BODY))); raise Err(413, "body too large")
                body = self.rfile.read(n)
                ep = self.path.lstrip("/")
                if ep not in ("poll", "status", "upload", "complete"):
                    raise Err(404, "unknown endpoint")
                auth = self.headers.get("Authorization", "")
                if not hmac.compare_digest(auth.encode(), ("Bearer " + mock.a.token).encode()):
                    raise Err(401, "bad token")
                if (self.headers.get("Content-Type") or "").split(";")[0] != "application/json":
                    raise Err(415, "content type")
                try:
                    b = json.loads(body)
                except ValueError:
                    raise Err(400, "bad json")
                if not isinstance(b, dict):
                    raise Err(400, "json object expected")
                with mock.lock:
                    try:
                        out = getattr(mock, ep)(b)
                    except Err as e:
                        if e.code != 503:
                            mock.errors.append("%s: %s" % (ep, e.msg))
                        raise
                self.send(200, out)
            except Err as e:
                self.send(e.code, {"error": e.msg})
    return H


def main():
    p = argparse.ArgumentParser()
    p.add_argument("--port", type=int, required=True); p.add_argument("--token", required=True)
    p.add_argument("--job-id", default=""); p.add_argument("--version", default="0.0.1-hosttest")
    p.add_argument("--ref", default="main"); p.add_argument("--targets", default="windows-amd64 windows-arm64")
    p.add_argument("--msi", action="store_true"); p.add_argument("--out-dir", default="")
    p.add_argument("--fail-uploads", type=int, default=0, help="answer the first N upload calls with 503")
    a = p.parse_args()
    srv = ThreadingHTTPServer(("127.0.0.1", a.port), make_handler(Mock(a)))
    print("mock build host server on 127.0.0.1:%d" % a.port, flush=True)
    srv.serve_forever()


if __name__ == "__main__":
    sys.exit(main())
