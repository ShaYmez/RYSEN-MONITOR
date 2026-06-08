import ast
import unittest
from os.path import getmtime
from pathlib import Path
from tempfile import TemporaryDirectory
from unittest.mock import MagicMock


REPO_ROOT = Path(__file__).resolve().parent
MONITOR_FILE = REPO_ROOT / "monitor.py"


def _load_functions(*names):
    source = MONITOR_FILE.read_text(encoding="utf-8")
    module = ast.parse(source)
    selected = [
        node
        for node in module.body
        if isinstance(node, ast.FunctionDef) and node.name in names
    ]
    code = "\n\n".join(ast.get_source_segment(source, node) for node in selected)
    namespace = {}
    exec(code, namespace)
    return namespace


class TestFillTablePathGuards(unittest.TestCase):
    def test_fill_table_skips_directory_path(self):
        namespace = _load_functions("fill_table")
        namespace.update(
            {
                "Path": Path,
                "logger": MagicMock(),
                "db_conn": MagicMock(),
                "csv_dict_reader": MagicMock(),
                "jload": MagicMock(),
                "SUB_FIELDS": (),
                "PEER_FIELDS": (),
                "TGID_FIELDS": (),
            }
        )

        with TemporaryDirectory() as tmpdir:
            Path(tmpdir, "data").mkdir()
            namespace["fill_table"](tmpdir, "data", "peer_ids")

        namespace["db_conn"].populate_tbl.assert_not_called()
        namespace["logger"].warning.assert_called_once()


class TestUpdateLocalPathGuards(unittest.TestCase):
    def _make_update_local_namespace(self, files_path, lcl_peer):
        namespace = _load_functions("update_local")
        namespace.update(
            {
                "Path": Path,
                "getmtime": getmtime,
                "logger": MagicMock(),
                "fill_table": MagicMock(),
                "lcl_lstmod": {
                    "peer_ids": None,
                    "subscriber_ids": None,
                    "talkgroup_ids": None,
                },
                "CONF": {
                    "FILES": {
                        "PATH": files_path,
                        "LCL_PEER": lcl_peer,
                        "LCL_SUBS": "",
                        "LCL_TGID": "",
                    }
                },
            }
        )
        return namespace

    def test_update_local_ignores_blank_override_filename(self):
        with TemporaryDirectory() as tmpdir:
            namespace = self._make_update_local_namespace(tmpdir, "   ")
            namespace["update_local"]("peer_ids")

        namespace["fill_table"].assert_not_called()
        namespace["logger"].warning.assert_not_called()

    def test_update_local_skips_directory_override_path(self):
        with TemporaryDirectory() as tmpdir:
            Path(tmpdir, "data").mkdir()
            namespace = self._make_update_local_namespace(tmpdir, "data")
            namespace["update_local"]("peer_ids")

        namespace["fill_table"].assert_not_called()
        namespace["logger"].warning.assert_called_once()

    def test_update_local_preserves_valid_file_behavior(self):
        with TemporaryDirectory() as tmpdir:
            local_file = Path(tmpdir, "peer_ids.json")
            local_file.write_text("{}", encoding="utf-8")
            expected_mtime = getmtime(local_file)
            namespace = self._make_update_local_namespace(tmpdir, "peer_ids.json")

            namespace["update_local"]("peer_ids")
            namespace["update_local"]("peer_ids")

        namespace["fill_table"].assert_called_once_with(
            tmpdir, "peer_ids.json", "peer_ids", wipe_tbl=False
        )
        self.assertEqual(
            namespace["lcl_lstmod"]["peer_ids"],
            expected_mtime,
        )


if __name__ == "__main__":
    unittest.main(verbosity=2)
