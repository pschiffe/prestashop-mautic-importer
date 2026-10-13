#!/usr/bin/env python3
"""Exercise the production cURL transport against an isolated HTTPS server.

Requires PHP with curl, Python 3, and the OpenSSL CLI. No external network,
production credentials, or Mautic installation is used.
"""
import http.server
import json
import pathlib
import ssl
import subprocess
import tempfile
import threading
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]


class KeepAliveServer(http.server.ThreadingHTTPServer):
    daemon_threads = True

    def __init__(self, certificate, key):
        super().__init__(("127.0.0.1", 0), RequestHandler)
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        context.load_cert_chain(certificate, key)
        self.socket = context.wrap_socket(self.socket, server_side=True)
        self.connections = {}
        self.requests = []
        self.lost_write_paths = set()

    def get_request(self):
        connection, address = super().get_request()
        self.connections[connection] = len(self.connections) + 1
        return connection, address


class RequestHandler(http.server.BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, *_args):
        pass

    def handle(self):
        try:
            super().handle()
        except (ssl.SSLError, ConnectionError):
            # Certificate rejection can disconnect the client before it sends HTTP.
            self.close_connection = True

    def respond(self):
        body = self.rfile.read(int(self.headers.get("Content-Length", 0))).decode()
        self.server.requests.append({
            "connection": self.server.connections[self.connection],
            "method": self.command,
            "path": self.path,
            "headers": dict(self.headers),
            "body": body,
        })
        status = 200
        response = {}
        if self.path == "/retry":
            status = 429
        elif self.path == "/redirect":
            status = 302
        elif self.path == "/oauth/v2/token":
            response = {"access_token": "test-token", "expires_in": 3600, "token_type": "bearer"}
        elif self.path.startswith("/api/contacts?"):
            response = {"contacts": [], "total": 0}
        elif self.path in self.server.lost_write_paths:
            attempts = [request for request in self.server.requests if request["path"] == self.path]
            if len(attempts) == 1:
                # Consume the write, then lose its response; a replay would appear successful.
                self.close_connection = True
                return
            response = {"contact": {
                "id": 42,
                "fields": {"all": {"email": "lost@example.test", "firstname": "Changed"}},
            }}
        encoded = json.dumps(response).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(encoded)))
        if self.path == "/retry":
            self.send_header("Retry-After", "7")
        elif self.path == "/redirect":
            self.send_header("Location", "/redirect-target")
        elif self.path == "/close":
            self.send_header("Connection", "close")
            self.close_connection = True
        self.end_headers()
        self.wfile.write(encoded)
        self.wfile.flush()

    do_GET = respond
    do_POST = respond
    do_PATCH = respond


class TransportTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.directory = tempfile.TemporaryDirectory(prefix="importer-https-")
        cls.certificate = pathlib.Path(cls.directory.name) / "certificate.pem"
        cls.key = pathlib.Path(cls.directory.name) / "key.pem"
        subprocess.run([
            "openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes",
            "-keyout", str(cls.key), "-out", str(cls.certificate), "-days", "1",
            "-subj", "/CN=localhost", "-addext", "subjectAltName=IP:127.0.0.1,DNS:localhost",
        ], check=True, capture_output=True, timeout=30)

    @classmethod
    def tearDownClass(cls):
        cls.directory.cleanup()

    def setUp(self):
        self.server = KeepAliveServer(self.certificate, self.key)
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()
        self.url = f"https://127.0.0.1:{self.server.server_port}"

    def tearDown(self):
        self.server.shutdown()
        self.server.server_close()
        self.thread.join(timeout=5)

    def run_php(self, code, *arguments, trusted=True):
        return json.loads(subprocess.check_output([
            "php", "-d", f"curl.cainfo={self.certificate if trusted else ''}",
            "-r", "require $argv[1]; " + code, str(ROOT / "import.php"), *arguments,
        ], text=True, timeout=30))

    def request_sequence(self, requests, trusted=True):
        return self.run_php(r"""
            $transport = \PrestaShopMautic\createCurlTransport();
            $results = [];
            foreach (json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR) as $request) {
                $results[] = $transport(...$request);
            }
            echo json_encode($results, JSON_THROW_ON_ERROR);
        """, json.dumps(requests), trusted=trusted)

    def test_reuse_does_not_leak_bodies_headers_or_retry_delay(self):
        results = self.request_sequence([
            ["POST", self.url + "/token", ["Content-Type: application/x-www-form-urlencoded"], "client_secret=test-secret"],
            ["GET", self.url + "/contacts", ["Authorization: Bearer first-token"], None],
            ["PATCH", self.url + "/edit", ["Authorization: Bearer first-token"], "firstname=Changed"],
            ["GET", self.url + "/retry", ["Authorization: Bearer first-token"], None],
            ["POST", self.url + "/token", [], "client_secret=renewed-secret"],
            ["GET", self.url + "/contacts", ["Authorization: Bearer second-token"], None],
        ])
        self.assertEqual([200, 200, 200, 429, 200, 200], [r["status"] for r in results])
        self.assertEqual([None, None, None, 7, None, None], [r["retry_after"] for r in results])
        requests = self.server.requests
        connections = [request["connection"] for request in requests]
        self.assertEqual(3, len(set(connections)))
        for index in (0, 2, 4):
            self.assertNotIn(connections[index], connections[:index])
        self.assertEqual(["POST", "GET", "PATCH", "GET", "POST", "GET"], [r["method"] for r in requests])
        self.assertEqual(["client_secret=test-secret", "", "firstname=Changed", "", "client_secret=renewed-secret", ""],
                         [r["body"] for r in requests])
        self.assertEqual([None, "Bearer first-token", "Bearer first-token", "Bearer first-token", None, "Bearer second-token"],
                         [r["headers"].get("Authorization") for r in requests])
        self.assertNotIn("Content-Type", requests[1]["headers"])
        self.assertNotIn("Content-Type", requests[5]["headers"])

    def test_server_closed_connection_is_reopened(self):
        results = self.request_sequence([
            ["GET", self.url + "/close", [], None],
            ["GET", self.url + "/after-close", [], None],
        ])
        self.assertEqual([200, 200], [r["status"] for r in results])
        self.assertEqual([1, 2], [r["connection"] for r in self.server.requests])

    def test_untrusted_certificate_is_rejected(self):
        result = self.request_sequence([["GET", self.url + "/untrusted", [], None]], trusted=False)[0]
        self.assertEqual(0, result["status"])
        self.assertEqual(60, result["errno"])
        self.assertEqual([], self.server.requests)

    def test_redirect_is_not_followed_and_http_is_rejected(self):
        results = self.request_sequence([
            ["GET", self.url + "/redirect", [], None],
            ["GET", self.url.replace("https://", "http://") + "/plaintext", [], None],
        ])
        self.assertEqual([302, 0], [r["status"] for r in results])
        self.assertEqual(1, results[1]["errno"])
        self.assertEqual(["/redirect"], [r["path"] for r in self.server.requests])

    def test_mautic_client_reuses_connection_for_authentication_and_lookups(self):
        results = self.run_php(r"""
            $client = new \PrestaShopMautic\MauticClient([
                'url' => $argv[2], 'client_id' => 'test-client', 'client_secret' => 'test-secret',
            ]);
            echo json_encode([
                $client->findByEmail('first@example.test'),
                $client->findByEmail('second@example.test'),
            ], JSON_THROW_ON_ERROR);
        """, self.url)
        self.assertEqual([None, None], results)
        requests = self.server.requests
        self.assertEqual([1, 1, 1], [r["connection"] for r in requests])
        self.assertEqual(["POST", "GET", "GET"], [r["method"] for r in requests])
        self.assertEqual(["", ""], [r["body"] for r in requests[1:]])

    def assert_lost_write_is_not_replayed(self, contact_id):
        path = "/api/contacts/new" if contact_id is None else f"/api/contacts/{contact_id}/edit"
        self.server.lost_write_paths.add(path)
        result = self.run_php(r"""
            $client = new \PrestaShopMautic\MauticClient([
                'url' => $argv[2], 'client_id' => 'test-client', 'client_secret' => 'test-secret',
            ]);
            $email = 'lost@example.test';
            $client->findByEmail($email); // Establish a reusable connection before the write.
            $id = $argv[3] === 'new' ? null : (int) $argv[3];
            $payload = $id === null ? ['email' => $email] : ['firstname' => 'Changed'];
            try {
                $client->save($id, $payload, $email);
                echo json_encode(['failed' => false], JSON_THROW_ON_ERROR);
            } catch (\PrestaShopMautic\ImportError) {
                echo json_encode(['failed' => true], JSON_THROW_ON_ERROR);
            }
        """, self.url, "new" if contact_id is None else str(contact_id))
        self.assertTrue(result["failed"])
        writes = [request for request in self.server.requests if request["path"] == path]
        self.assertEqual(1, len(writes))
        self.assertEqual("POST" if contact_id is None else "PATCH", writes[0]["method"])
        self.assertEqual("email=lost%40example.test" if contact_id is None else "firstname=Changed", writes[0]["body"])
        lookups = [request for request in self.server.requests if request["method"] == "GET"]
        self.assertNotEqual(lookups[-1]["connection"], writes[0]["connection"])

    def test_lost_create_response_is_not_replayed(self):
        self.assert_lost_write_is_not_replayed(None)

    def test_lost_update_response_is_not_replayed(self):
        self.assert_lost_write_is_not_replayed(42)


if __name__ == "__main__":
    unittest.main(verbosity=2)
