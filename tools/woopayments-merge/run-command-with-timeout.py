#!/usr/bin/env python3
"""Run one command with bounded process-group cleanup and streamed output."""

from __future__ import annotations

import argparse
import os
import selectors
import signal
import subprocess
import sys
import time
from pathlib import Path
from typing import BinaryIO


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--timeout", required=True, type=float)
    parser.add_argument("--stdin-file", type=Path)
    parser.add_argument("command", nargs=argparse.REMAINDER)
    args = parser.parse_args()
    if args.command[:1] == ["--"]:
        args.command = args.command[1:]
    if not args.command:
        parser.error("a command is required")
    return args


def emit_chunk(chunk: bytes, state: dict[str, int | None]) -> None:
    if not chunk:
        return
    state["last_output_byte"] = chunk[-1]
    sys.stdout.buffer.write(chunk)
    sys.stdout.buffer.flush()


def main() -> int:
    args = parse_args()
    if args.timeout < 0:
        print("timeout must be non-negative", file=sys.stderr)
        return 2

    stdin_stream: BinaryIO | None = None
    if args.stdin_file is not None:
        stdin_stream = args.stdin_file.open("rb")

    try:
        if args.timeout == 0:
            return subprocess.run(args.command, stdin=stdin_stream, check=False).returncode

        process = subprocess.Popen(
            args.command,
            stdin=stdin_stream,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            bufsize=0,
            start_new_session=True,
        )
        assert process.stdout is not None

        selector = selectors.DefaultSelector()
        selector.register(process.stdout.fileno(), selectors.EVENT_READ)
        deadline = time.monotonic() + args.timeout
        output_state: dict[str, int | None] = {"last_output_byte": None}

        def drain_ready(wait_seconds: float) -> None:
            while True:
                events = selector.select(timeout=wait_seconds)
                if not events:
                    return
                for key, _ in events:
                    chunk = os.read(key.fd, 65536)
                    if not chunk:
                        try:
                            selector.unregister(key.fd)
                        except (KeyError, ValueError):
                            pass
                        return
                    emit_chunk(chunk, output_state)
                wait_seconds = 0

        def process_group_exists() -> bool:
            try:
                os.killpg(process.pid, 0)
            except ProcessLookupError:
                return False
            return True

        try:
            while True:
                drain_ready(0.1)
                return_code = process.poll()
                if return_code is not None and not selector.get_map():
                    return return_code

                if time.monotonic() < deadline:
                    continue

                if output_state["last_output_byte"] not in (None, ord("\n")):
                    print(flush=True)
                print(
                    f"TIMEOUT: command exceeded {args.timeout:g}s; terminating process group.",
                    flush=True,
                )
                try:
                    os.killpg(process.pid, signal.SIGTERM)
                except ProcessLookupError:
                    pass

                grace_deadline = time.monotonic() + 5
                while time.monotonic() < grace_deadline:
                    drain_ready(0.1)
                    process.poll()
                    if not process_group_exists() and not selector.get_map():
                        break

                if process.poll() is None or process_group_exists() or selector.get_map():
                    print(
                        "TIMEOUT: command did not exit after SIGTERM; sending SIGKILL.",
                        flush=True,
                    )
                    try:
                        os.killpg(process.pid, signal.SIGKILL)
                    except ProcessLookupError:
                        pass

                if process.poll() is None:
                    try:
                        process.wait(timeout=5)
                    except subprocess.TimeoutExpired:
                        pass

                drain_deadline = time.monotonic() + 1
                while selector.get_map() and time.monotonic() < drain_deadline:
                    drain_ready(0.1)
                return 124
        finally:
            selector.close()
    finally:
        if stdin_stream is not None:
            stdin_stream.close()


if __name__ == "__main__":
    raise SystemExit(main())
