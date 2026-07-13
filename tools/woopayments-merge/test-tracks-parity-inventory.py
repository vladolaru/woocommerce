from __future__ import annotations

import os
import subprocess
from pathlib import Path


REPO = Path(__file__).resolve().parents[2]
GATE = REPO / "tools/woopayments-merge/tracks-parity.sh"


def run_inventory(target: Path, inventory: Path) -> subprocess.CompletedProcess[str]:
    env = os.environ.copy()
    env.update(
        {
            "TRACKS_TARGET_ROOT": str(target),
            "TRACKS_INVENTORY": str(inventory),
        }
    )
    return subprocess.run(
        ["bash", str(GATE), "inventory"],
        cwd=REPO,
        env=env,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        check=False,
    )


def write_inventory(path: Path) -> None:
    path.write_text(
        "# final_event\tnative_event\tcustom_property_keys\tnative_owner\n"
        "wcpay_checkout_page_view\tcheckout_page_view\ttheme_type,woopay_enabled\towner.php\n",
        encoding="utf-8",
    )


def test_inventory_fails_when_native_event_contract_is_missing(tmp_path: Path) -> None:
    inventory = tmp_path / "tracks.tsv"
    write_inventory(inventory)
    (tmp_path / "owner.php").write_text("<?php\n", encoding="utf-8")

    result = run_inventory(tmp_path, inventory)

    assert result.returncode == 1, result.stdout
    assert "wcpay_checkout_page_view" in result.stdout
    assert "missing native event token" in result.stdout


def test_inventory_rejects_header_only_contract_list(tmp_path: Path) -> None:
    inventory = tmp_path / "tracks.tsv"
    inventory.write_text(
        "# final_event\tnative_event\tcustom_property_keys\tnative_owner\n",
        encoding="utf-8",
    )

    result = run_inventory(tmp_path, inventory)

    assert result.returncode != 0, result.stdout
    assert "does not contain any contracts" in result.stdout


def test_inventory_accepts_exact_native_event_and_property_contract(tmp_path: Path) -> None:
    inventory = tmp_path / "tracks.tsv"
    write_inventory(inventory)
    (tmp_path / "owner.php").write_text(
        "<?php\n$tracker->record_user_event( 'checkout_page_view', array( 'theme_type' => 'blocks', 'woopay_enabled' => true ) );\n",
        encoding="utf-8",
    )

    result = run_inventory(tmp_path, inventory)

    assert result.returncode == 0, result.stdout
    assert "PASS: 1 native Tracks continuity contracts present." in result.stdout
