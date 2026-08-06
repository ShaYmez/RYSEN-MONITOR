#!/usr/bin/env python3
"""Tests for SERVER_INFO_SND handling in monitor.py."""
import json
import unittest
from unittest.mock import patch

import monitor


class TestServerInfoOpcode(unittest.TestCase):

    def test_opcode_defined(self):
        self.assertEqual(monitor.OPCODE["SERVER_INFO_SND"], "\x08")

    @patch("monitor.push_rysen_version")
    def test_process_message_sets_rysen_version(self, mock_push):
        payload = json.dumps({"rysen_version": "1.5.3", "hostname": "test"}).encode("utf-8")
        message = b"\x08" + payload
        monitor.RYSEN_VERSION = ""
        monitor.process_message(message)
        self.assertEqual(monitor.RYSEN_VERSION, "1.5.3")
        mock_push.assert_called_once()

    @patch("monitor.push_rysen_version")
    def test_process_message_ignores_invalid_json(self, mock_push):
        monitor.RYSEN_VERSION = "1.5.2"
        monitor.process_message(b"\x08not-json")
        self.assertEqual(monitor.RYSEN_VERSION, "1.5.2")
        mock_push.assert_not_called()


if __name__ == "__main__":
    unittest.main()
