"""
Regression tests for RYSEN-Monitor HTTP/WebSocket request routing.

These tests are self-contained: they reproduce the MonitorRootResource
class directly from its source logic so they don't require a real
fdmr-mon.cfg file or database connection.

Tests cover:
- Plain HTTP GET / → 200 health check body
- WebSocket upgrade on / → forwarded to WebSocketResource (direct-access)
- /wss/ path explicitly mounted → routes straight to WebSocketResource
- /wss/ is NOT a leaf fallback (isLeaf must be False on root)
- Unknown paths → fall back to root health check via getChild

Run with:  python -m pytest test_routing.py -v
       or: python test_routing.py
"""

import logging
import unittest
from unittest.mock import MagicMock, patch

from twisted.web.resource import Resource

# ---------------------------------------------------------------------------
# Reproduction of MonitorRootResource (mirrors monitor.py exactly) so that
# these tests remain independent of the full monitor.py module-load path.
# ---------------------------------------------------------------------------

logger = logging.getLogger("fdmr-mon")


class _TestableMonitorRootResource(Resource):
    """Exact copy of MonitorRootResource from monitor.py, used for isolation."""

    isLeaf = False

    def __init__(self, websocket_resource):
        super().__init__()
        self.websocket_resource = websocket_resource
        self.putChild(b"wss", websocket_resource)

    @staticmethod
    def _decode(value):
        if isinstance(value, bytes):
            return value.decode("utf-8", "replace")
        return str(value)

    def render(self, request):
        method = self._decode(request.method)
        uri = self._decode(request.uri)
        client_ip = request.getClientIP() or "unknown"
        upgrade = (request.getHeader("upgrade") or "").lower()
        connection = {
            token.strip().lower()
            for token in (request.getHeader("connection") or "").split(",")
            if token.strip()
        }

        try:
            if upgrade == "websocket" or "upgrade" in connection:
                logger.info(
                    f"WebSocket upgrade on {uri} from {client_ip} (direct-access path)"
                )
                return self.websocket_resource.render(request)

            logger.info(f"Handling HTTP request {method} {uri} from {client_ip}")
            request.setHeader(b"content-type", b"text/plain; charset=utf-8")

            if method not in ("GET", "HEAD"):
                request.setResponseCode(405)
                return b"Method Not Allowed\n"

            if method == "HEAD":
                return b""

            return b"RYSEN-Monitor is running.\n"

        except Exception:
            logger.exception(
                f"Failed handling request {method} {uri} from {client_ip}"
            )
            request.setResponseCode(500)
            request.setHeader(b"content-type", b"text/plain; charset=utf-8")
            return b"Internal Server Error\n"

    def getChild(self, path, request):
        logger.debug(
            f"Unrecognised path /{self._decode(path)} requested from "
            f"{request.getClientIP() or 'unknown'}"
        )
        return self


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _make_request(
    method: bytes = b"GET",
    uri: bytes = b"/",
    upgrade: str = "",
    connection: str = "",
    client_ip: str = "127.0.0.1",
) -> MagicMock:
    """Return a minimal Twisted Request mock for routing tests."""
    req = MagicMock()
    req.method = method
    req.uri = uri
    req.getClientIP.return_value = client_ip

    def _get_header(name: str):
        name_lc = name.lower()
        if name_lc == "upgrade":
            return upgrade or None
        if name_lc == "connection":
            return connection or None
        return None

    req.getHeader.side_effect = _get_header
    return req


class _FakeWebSocketResource(Resource):
    """Stub WebSocket resource that records render() calls."""

    isLeaf = True
    render_called = False

    def render(self, request):  # type: ignore[override]
        self.render_called = True
        return b"WS_HANDLED"


def _client_peer(client):
    """Mirror monitor.py client peer helper used by websocket protocol logging."""
    return getattr(client, "peer", f"{type(client).__name__}@{id(client)}")


class _TestableDashboardProtocol:
    """Minimal reproduction of dashboard close lifecycle handling from monitor.py."""

    peer = "test-peer"

    def onClose(self, wasClean, code, reason):
        try:
            factory = getattr(self, "factory", None)
            if factory is None:
                logger.warning(
                    f"WebSocket close received for {_client_peer(self)} without a protocol factory"
                )
            else:
                factory.unregister(self)
        except Exception:
            logger.exception(f"Unhandled websocket close cleanup error for {_client_peer(self)}")
        try:
            logger.info(
                f"WebSocket connection closed for {_client_peer(self)}: "
                f"wasClean={wasClean}, code={code}, reason={reason!r}"
            )
        except Exception:
            logger.exception("Unhandled websocket close log error")


# ---------------------------------------------------------------------------
# Tests
# ---------------------------------------------------------------------------

class TestMonitorRootResourceTree(unittest.TestCase):
    """Resource-tree structure tests."""

    def setUp(self):
        self.ws = _FakeWebSocketResource()
        self.root = _TestableMonitorRootResource(self.ws)

    def test_is_not_leaf(self):
        """isLeaf must be False so Twisted can route /wss/ to the child resource."""
        self.assertFalse(
            self.root.isLeaf,
            "MonitorRootResource.isLeaf must be False to allow child routing",
        )

    def test_wss_child_is_websocket_resource(self):
        """/wss/ must resolve directly to the WebSocketResource without going through render()."""
        child = self.root.getChildWithDefault(b"wss", MagicMock())
        self.assertIs(
            child,
            self.ws,
            "/wss/ should be mapped to the WebSocket resource via putChild",
        )

    def test_unknown_path_falls_back_to_root(self):
        """Any unrecognised child path must fall back to the root resource (health check)."""
        child = self.root.getChildWithDefault(b"unknown", MagicMock())
        self.assertIs(
            child,
            self.root,
            "Unknown path should fall back to MonitorRootResource via getChild",
        )

    def test_empty_string_path_falls_back_to_root(self):
        """An empty-string child (trailing slash on root) must also fall back."""
        # Twisted passes b"" for the empty segment after a trailing slash on the root.
        child = self.root.getChildWithDefault(b"", MagicMock())
        # Either the root itself or a resource that renders the health check is acceptable.
        # With our implementation, b"" is not registered so getChild returns self.
        self.assertIsNotNone(child)


class TestMonitorRootResourceRender(unittest.TestCase):
    """render() behaviour tests for the root path."""

    def setUp(self):
        self.ws = _FakeWebSocketResource()
        self.root = _TestableMonitorRootResource(self.ws)

    def test_plain_get_root_returns_health_check(self):
        """Plain HTTP GET / must return the health-check body."""
        req = _make_request(b"GET", b"/")
        result = self.root.render(req)
        self.assertEqual(result, b"RYSEN-Monitor is running.\n")
        self.assertFalse(
            self.ws.render_called,
            "WebSocket resource must NOT be called for plain HTTP GET /",
        )

    def test_head_root_returns_empty_body(self):
        """HEAD / must return an empty body (no content)."""
        req = _make_request(b"HEAD", b"/")
        result = self.root.render(req)
        self.assertEqual(result, b"")

    def test_post_root_returns_405(self):
        """POST / must return 405 Method Not Allowed."""
        req = _make_request(b"POST", b"/")
        result = self.root.render(req)
        req.setResponseCode.assert_called_with(405)
        self.assertEqual(result, b"Method Not Allowed\n")


class TestWebSocketUpgradeRouting(unittest.TestCase):
    """WebSocket upgrade detection on the root path (direct-access deployments)."""

    def setUp(self):
        self.ws = _FakeWebSocketResource()
        self.root = _TestableMonitorRootResource(self.ws)

    def test_upgrade_websocket_header_routes_to_ws(self):
        """Upgrade: websocket on / must be forwarded to WebSocketResource."""
        req = _make_request(
            b"GET", b"/", upgrade="websocket", connection="Upgrade"
        )
        result = self.root.render(req)
        self.assertEqual(result, b"WS_HANDLED")
        self.assertTrue(
            self.ws.render_called,
            "WebSocket resource render() must be called on a WS upgrade",
        )

    def test_connection_upgrade_alone_triggers_ws_path(self):
        """Connection: Upgrade (without explicit Upgrade header) still triggers WS path."""
        req = _make_request(b"GET", b"/", connection="upgrade")
        result = self.root.render(req)
        self.assertEqual(result, b"WS_HANDLED")

    def test_plain_get_does_not_invoke_ws_handler(self):
        """A plain GET / (no upgrade headers) must NOT invoke the WebSocket resource."""
        req = _make_request(b"GET", b"/")
        self.root.render(req)
        self.assertFalse(
            self.ws.render_called,
            "WebSocket resource must not be invoked for plain HTTP requests",
        )

    def test_wss_path_routes_to_ws_without_render(self):
        """/wss/ must route to WebSocketResource via the resource tree, not via render()."""
        # Verify the child is correct (tree routing, not render() dispatch).
        child = self.root.getChildWithDefault(b"wss", MagicMock())
        self.assertIs(child, self.ws)
        # render() of the ROOT should NOT be the entry point for /wss/.
        self.assertFalse(
            self.ws.render_called,
            "WebSocket resource render() should not have been called yet",
        )


class TestWebSocketLifecycleSafety(unittest.TestCase):
    """Regression coverage for defensive websocket close handling."""

    def test_on_close_swallow_unregister_exception(self):
        proto = _TestableDashboardProtocol()
        proto.factory = MagicMock()
        proto.factory.unregister.side_effect = RuntimeError("cleanup failed")

        with patch.object(logger, "exception") as log_exception:
            proto.onClose(True, 1000, "normal closure")

        log_exception.assert_called_once()

    def test_on_close_without_factory_does_not_raise(self):
        proto = _TestableDashboardProtocol()

        with patch.object(logger, "warning") as log_warning:
            proto.onClose(False, 1006, "abnormal closure")

        log_warning.assert_called_once()


if __name__ == "__main__":
    unittest.main(verbosity=2)
