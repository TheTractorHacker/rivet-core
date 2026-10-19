#!/usr/bin/env python3
"""Tests that need no Docker: the mock server's validation, and the parts of build-host.sh that act before any build
(https-only, config permissions, ref allowlist refusal reported as failed, lock, token never printed).
Run: python3 -m unittest endpoint-agent/e2e/test_build_host.py"""
import warnings
warnings.simplefilter('ignore', ResourceWarning)
import base64, fcntl, hashlib, json, os, subprocess, sys, tempfile, threading, time, unittest, urllib.request, urllib.error
from http.server import ThreadingHTTPServer

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import mock_build_host_server as M  # noqa: E402

SCRIPT = os.path.join(HERE, "..", "scripts", "build-host.sh")
TOKEN = "tok_SECRET_0123456789abcdef"


class Args:
    token = TOKEN; job_id = "j1"; version = "1.2.3"; ref = "main"; targets = "windows-amd64 windows-arm64"
    msi = False; out_dir = ""; fail_uploads = 0


def start(**kw):
    a = Args()
    for k, v in kw.items():
        setattr(a, k, v)
    mock = M.Mock(a)
    srv = ThreadingHTTPServer(("127.0.0.1", 0), M.make_handler(mock))
    threading.Thread(target=srv.serve_forever, daemon=True).start()
    return mock, srv, "http://127.0.0.1:%d" % srv.server_address[1]


def post(base, ep, obj, token=TOKEN, ctype="application/json", raw=None):
    req = urllib.request.Request(base + "/" + ep, data=raw if raw is not None else json.dumps(obj).encode(), method="POST",
                                 headers={"Authorization": "Bearer " + token, "Content-Type": ctype})
    try:
        with urllib.request.urlopen(req) as r:
            return r.status, json.loads(r.read())
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read())


def up(name, data, idx=0, total=1, **over):
    d = {"job_id": "j1", "name": name, "index": idx, "total": total,
         "sha256_chunk": hashlib.sha256(data).hexdigest(), "data_b64": base64.b64encode(data).decode()}
    d.update(over)
    return d


POLL = {"host_version": "1.0.0", "capabilities": {"docker": True, "msi": True}}


class MockServer(unittest.TestCase):
    def setUp(self):
        self.mock, self.srv, self.base = start()
        self.addCleanup(self.srv.shutdown)

    def test_auth_and_content_type(self):
        self.assertEqual(post(self.base, "poll", POLL, token="nope")[0], 401)
        self.assertEqual(post(self.base, "poll", POLL, ctype="text/plain")[0], 415)
        self.assertEqual(post(self.base, "poll", None, raw=b"{bad")[0], 400)
        self.assertEqual(post(self.base, "other", POLL)[0], 404)

    def test_poll_claims_once(self):
        self.assertEqual(post(self.base, "poll", POLL)[1]["job"]["id"], "j1")
        self.assertIsNone(post(self.base, "poll", POLL)[1]["job"])
        self.assertEqual(post(self.base, "poll", dict(POLL, extra=1))[0], 400)

    def test_upload_rules(self):
        post(self.base, "poll", POLL)
        exe = b"MZ" + b"x" * 10
        self.assertEqual(post(self.base, "upload", up("rivetit-agent-windows-amd64.exe", exe))[0], 200)
        self.assertEqual(post(self.base, "upload", up("../evil", exe))[0], 400)
        self.assertEqual(post(self.base, "upload", up("rivetit-agent-windows-amd64.msi", exe))[0], 400)  # job has no msi
        self.assertEqual(post(self.base, "upload", up("rivetit-agent-windows-amd64.exe", exe, sha256_chunk="0" * 64))[0], 400)
        self.assertEqual(post(self.base, "upload", up("rivetit-agent-windows-amd64.exe", exe, index=1))[0], 400)
        big = b"a" * (M.MAX_CHUNK + 1)
        self.assertEqual(post(self.base, "upload", up("rivetit-agent-windows-amd64.exe", big))[0], 400)
        self.assertEqual(post(self.base, "upload", up("SHA256SUMS", exe, data_b64="@@@"))[0], 400)

    def test_complete_verifies_hashes(self):
        post(self.base, "poll", POLL)
        exe = b"MZ" + b"y" * 5
        name = "rivetit-agent-windows-amd64.exe"
        post(self.base, "upload", up(name, exe))
        sums = ("%s  %s\n" % (hashlib.sha256(exe).hexdigest(), name)).encode()
        post(self.base, "upload", up("SHA256SUMS", sums))
        man = {"files": {name: "0" * 64}, "commit": "a" * 40, "go_version": "go1.27.1", "built_at": "2026-10-19T10:00:00Z"}
        self.assertEqual(post(self.base, "complete", {"job_id": "j1", "manifest": man})[0], 400)
        man["files"][name] = hashlib.sha256(exe).hexdigest()
        self.assertEqual(post(self.base, "complete", {"job_id": "j1", "manifest": dict(man, commit="short")})[0], 400)
        self.assertEqual(post(self.base, "complete", {"job_id": "j1", "manifest": man})[0], 200)
        self.assertEqual(self.mock.state, "built")

    def test_status_rules(self):
        post(self.base, "poll", POLL)
        self.assertEqual(post(self.base, "status", {"job_id": "j1", "state": "building", "note": "x" * 501})[0], 400)
        self.assertEqual(post(self.base, "status", {"job_id": "j1", "state": "building", "note": "a", "log_tail": "t"})[0], 400)
        self.assertEqual(post(self.base, "status", {"job_id": "j1", "state": "weird", "note": "a"})[0], 400)
        self.assertEqual(post(self.base, "status", {"job_id": "zz", "state": "building", "note": "a"})[0], 404)
        self.assertEqual(post(self.base, "status", {"job_id": "j1", "state": "failed", "note": "a", "log_tail": "t"})[0], 200)


class ScriptNoDocker(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.mkdtemp(prefix="bh-unit-")
        self.work = os.path.join(self.tmp, "work")

    def conf(self, url, refs="main v*", mode=0o600, token=TOKEN):
        p = os.path.join(self.tmp, "build-host.conf")
        with open(p, "w") as f:
            f.write('SERVER_URL=%s\nTOKEN=%s\nCORE_REPO=%s\nALLOWED_REFS="%s"\nWORKDIR=%s\n' % (url, token, self.tmp, refs, self.work))
        os.chmod(p, mode)
        return p

    def run_script(self, conf, *args, allow_http=True):
        env = dict(os.environ, BUILD_HOST_RETRY_DELAY="1")
        if allow_http:
            env["BUILD_HOST_ALLOW_HTTP"] = "1"
        else:
            env.pop("BUILD_HOST_ALLOW_HTTP", None)
        return subprocess.run(["bash", SCRIPT, "--config", conf, *args], capture_output=True, text=True, env=env, timeout=120)

    def test_http_refused_without_override(self):
        mock, srv, base = start(); self.addCleanup(srv.shutdown)
        r = self.run_script(self.conf(base), allow_http=False)
        self.assertNotEqual(r.returncode, 0)
        self.assertIn("https", r.stderr)
        self.assertEqual(mock.events, [])

    def test_non_http_schemes_and_credentials_refused(self):
        for url in ("ftp://example.com/x", "https://user:pw@example.com/x", "example.com/x"):
            r = self.run_script(self.conf(url))
            self.assertNotEqual(r.returncode, 0, url)

    def test_config_permissions(self):
        r = self.run_script(self.conf("https://example.com/x", mode=0o644))
        self.assertNotEqual(r.returncode, 0)
        self.assertIn("chmod 600", r.stderr)

    def test_refused_ref_is_reported_failed(self):
        mock, srv, base = start(ref="evil-branch"); self.addCleanup(srv.shutdown)
        r = self.run_script(self.conf(base))
        self.assertNotEqual(r.returncode, 0)
        self.assertEqual(mock.state, "failed")
        self.assertIn("not in this build host's ALLOWED_REFS", mock.statuses[-1]["note"])
        self.assertNotIn(TOKEN, r.stdout + r.stderr)
        self.assertNotIn(TOKEN, json.dumps(mock.snapshot()))
        self.assertEqual(os.listdir(os.path.join(self.work, "tmp")), [])  # temp dir removed

    def test_hostile_ref_and_version_refused(self):
        for ref, ver in (("main/../x", "1.2.3"), ("-x", "1.2.3"), ("main", "1.2.3; rm -rf /"), ("main", "1.2")):
            mock, srv, base = start(ref=ref, version=ver); self.addCleanup(srv.shutdown)
            r = self.run_script(self.conf(base))
            self.assertNotEqual(r.returncode, 0, (ref, ver))
            self.assertEqual(mock.state, "failed", (ref, ver))

    def test_no_job(self):
        mock, srv, base = start(job_id=""); self.addCleanup(srv.shutdown)
        r = self.run_script(self.conf(base))
        self.assertEqual(r.returncode, 0, r.stderr)
        self.assertIn("no job", r.stderr)

    def test_lock_held(self):
        mock, srv, base = start(); self.addCleanup(srv.shutdown)
        os.makedirs(self.work, exist_ok=True)
        fd = os.open(os.path.join(self.work, "build-host.lock"), os.O_CREAT | os.O_RDWR)
        fcntl.flock(fd, fcntl.LOCK_EX)
        try:
            r = self.run_script(self.conf(base))
        finally:
            os.close(fd)
        self.assertEqual(r.returncode, 0)
        self.assertIn("lock", r.stderr)
        self.assertEqual(mock.state, "queued")  # nothing was claimed

    def test_register_does_not_claim(self):
        mock, srv, base = start(); self.addCleanup(srv.shutdown)
        r = self.run_script(self.conf(base), "--register")
        self.assertEqual(r.returncode, 0, r.stderr)  # needs a usable docker daemon (true on CI runners)
        self.assertIn("OK build host", r.stdout)
        self.assertNotEqual(mock.state, "building")


if __name__ == "__main__":
    unittest.main()
