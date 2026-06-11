#!/usr/bin/env python3
"""
Reproduzierbarer HTTP- und WebDAV-Benchmark fuer ownCloud.online.

Modified by BW-Tech GmbH.
"""

from __future__ import annotations

import argparse
import base64
import concurrent.futures
import getpass
import json
import math
import os
import ssl
import statistics
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Iterable


SUCCESS_CODES = {
    "GET": {200, 206},
    "MKCOL": {201},
    "PROPFIND": {207},
    "PUT": {201, 204},
    "DELETE": {204},
}


@dataclass(frozen=True)
class RequestResult:
    status: int
    duration_seconds: float
    response_bytes: int


class BenchmarkError(RuntimeError):
    pass


class WebDavClient:
    def __init__(
        self,
        base_url: str,
        username: str,
        password: str,
        timeout: float,
        verify_tls: bool,
    ) -> None:
        self.base_url = base_url.rstrip("/")
        self.timeout = timeout
        credentials = f"{username}:{password}".encode("utf-8")
        self.authorization = "Basic " + base64.b64encode(credentials).decode("ascii")

        handlers: list[urllib.request.BaseHandler] = []
        if self.base_url.startswith("https://") and not verify_tls:
            context = ssl.create_default_context()
            context.check_hostname = False
            context.verify_mode = ssl.CERT_NONE
            handlers.append(urllib.request.HTTPSHandler(context=context))
        self.opener = urllib.request.build_opener(*handlers)

    def request(
        self,
        method: str,
        path: str,
        *,
        body: bytes | None = None,
        headers: dict[str, str] | None = None,
        authenticated: bool = True,
    ) -> RequestResult:
        request_headers = {
            "Accept": "*/*",
            "Connection": "keep-alive",
            "User-Agent": "BW-Tech-ownCloud.online-performance-benchmark/1.0",
        }
        if authenticated:
            request_headers["Authorization"] = self.authorization
        if headers:
            request_headers.update(headers)

        request = urllib.request.Request(
            self.base_url + path,
            data=body,
            headers=request_headers,
            method=method,
        )
        started = time.perf_counter()
        try:
            with self.opener.open(request, timeout=self.timeout) as response:
                response_bytes = _consume_response(response)
                status = response.status
        except urllib.error.HTTPError as error:
            response_bytes = _consume_response(error)
            status = error.code
        except urllib.error.URLError as error:
            raise BenchmarkError(f"{method} {path} fehlgeschlagen: {error}") from error

        duration = time.perf_counter() - started
        if status not in SUCCESS_CODES.get(method, {200}):
            raise BenchmarkError(
                f"{method} {path} lieferte HTTP {status} nach {duration:.3f}s"
            )
        return RequestResult(status, duration, response_bytes)


def _consume_response(response: object) -> int:
    total = 0
    while True:
        chunk = response.read(1024 * 1024)
        if not chunk:
            return total
        total += len(chunk)


def _percentile(values: list[float], percentile: float) -> float:
    if not values:
        return 0.0
    ordered = sorted(values)
    index = max(0, math.ceil(percentile * len(ordered)) - 1)
    return ordered[index]


def _latency_metrics(results: Iterable[RequestResult]) -> dict[str, float | int]:
    result_list = list(results)
    durations_ms = [result.duration_seconds * 1000 for result in result_list]
    return {
        "requests": len(result_list),
        "min_ms": round(min(durations_ms), 2) if durations_ms else 0.0,
        "mean_ms": round(statistics.fmean(durations_ms), 2) if durations_ms else 0.0,
        "p50_ms": round(_percentile(durations_ms, 0.50), 2),
        "p95_ms": round(_percentile(durations_ms, 0.95), 2),
        "p99_ms": round(_percentile(durations_ms, 0.99), 2),
        "max_ms": round(max(durations_ms), 2) if durations_ms else 0.0,
    }


def _transfer_metrics(
    results: Iterable[RequestResult],
    total_payload_bytes: int,
    wall_seconds: float,
) -> dict[str, float | int]:
    metrics = _latency_metrics(results)
    metrics.update(
        {
            "payload_bytes": total_payload_bytes,
            "wall_seconds": round(wall_seconds, 3),
            "throughput_mib_s": round(
                total_payload_bytes / max(wall_seconds, 0.000001) / 1024 / 1024,
                3,
            ),
        }
    )
    return metrics


def _payload(size: int) -> bytes:
    marker = b"BW-Tech-ownCloud.online-performance\n"
    repeats, remainder = divmod(size, len(marker))
    return marker * repeats + marker[:remainder]


def _quote_path(*parts: str) -> str:
    return "/" + "/".join(urllib.parse.quote(part, safe="") for part in parts)


def _run_parallel_uploads(
    client: WebDavClient,
    folder_path: str,
    payload: bytes,
    count: int,
    concurrency: int,
) -> tuple[list[RequestResult], float]:
    def upload(index: int) -> RequestResult:
        return client.request(
            "PUT",
            f"{folder_path}/small-{index:06d}.bin",
            body=payload,
            headers={"Content-Type": "application/octet-stream"},
        )

    started = time.perf_counter()
    with concurrent.futures.ThreadPoolExecutor(max_workers=concurrency) as executor:
        results = list(executor.map(upload, range(count)))
    return results, time.perf_counter() - started


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Misst API-, WebDAV- und Transfer-Performance."
    )
    parser.add_argument(
        "--base-url",
        default=os.getenv("OC_BASE_URL", "http://127.0.0.1:8088"),
    )
    parser.add_argument("--username", default=os.getenv("OC_USERNAME", ""))
    parser.add_argument("--password", default=os.getenv("OC_PASSWORD", ""))
    parser.add_argument("--latency-requests", type=int, default=20)
    parser.add_argument("--small-files", type=int, default=100)
    parser.add_argument("--small-size", type=int, default=4096)
    parser.add_argument("--large-size", type=int, default=16 * 1024 * 1024)
    parser.add_argument("--concurrency", type=int, default=4)
    parser.add_argument("--timeout", type=float, default=120.0)
    parser.add_argument("--output", type=Path)
    parser.add_argument("--keep-data", action="store_true")
    parser.add_argument("--insecure", action="store_true")
    args = parser.parse_args()

    for name in ("latency_requests", "small_files", "small_size", "large_size", "concurrency"):
        if getattr(args, name) <= 0:
            parser.error(f"--{name.replace('_', '-')} muss groesser als 0 sein")
    if not args.username:
        parser.error("--username oder OC_USERNAME fehlt")
    if not args.password:
        args.password = getpass.getpass("ownCloud-Passwort: ")
    return args


def main() -> int:
    args = parse_args()
    client = WebDavClient(
        args.base_url,
        args.username,
        args.password,
        args.timeout,
        not args.insecure,
    )

    run_id = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    folder_name = f"bwtech-performance-{run_id}-{uuid.uuid4().hex[:8]}"
    dav_user_path = _quote_path("remote.php", "dav", "files", args.username)
    folder_path = f"{dav_user_path}/{urllib.parse.quote(folder_name, safe='')}"
    propfind_body = b"""<?xml version="1.0" encoding="utf-8"?>
<d:propfind xmlns:d="DAV:">
  <d:prop>
    <d:getetag/>
    <d:getcontentlength/>
    <d:getlastmodified/>
    <d:resourcetype/>
  </d:prop>
</d:propfind>
"""

    report: dict[str, object] = {
        "schema_version": 1,
        "created_at": datetime.now(timezone.utc).isoformat(),
        "base_url": args.base_url,
        "parameters": {
            "latency_requests": args.latency_requests,
            "small_files": args.small_files,
            "small_size": args.small_size,
            "large_size": args.large_size,
            "concurrency": args.concurrency,
        },
        "metrics": {},
    }
    metrics = report["metrics"]
    assert isinstance(metrics, dict)

    large_name = "large.bin"
    large_payload = _payload(args.large_size)
    small_payload = _payload(args.small_size)
    folder_created = False

    try:
        # Warmup reduziert einmalige Autoloader- und OPcache-Effekte.
        for _ in range(3):
            client.request("GET", "/status.php", authenticated=False)

        status_results = [
            client.request("GET", "/status.php", authenticated=False)
            for _ in range(args.latency_requests)
        ]
        metrics["status"] = _latency_metrics(status_results)

        capability_results = [
            client.request(
                "GET",
                "/ocs/v1.php/cloud/capabilities?format=json",
                headers={"OCS-APIRequest": "true"},
            )
            for _ in range(args.latency_requests)
        ]
        metrics["capabilities"] = _latency_metrics(capability_results)

        client.request("MKCOL", folder_path)
        folder_created = True

        small_results, small_wall = _run_parallel_uploads(
            client,
            folder_path,
            small_payload,
            args.small_files,
            args.concurrency,
        )
        metrics["small_file_upload"] = _transfer_metrics(
            small_results,
            args.small_files * args.small_size,
            small_wall,
        )

        large_upload_started = time.perf_counter()
        large_upload = client.request(
            "PUT",
            f"{folder_path}/{large_name}",
            body=large_payload,
            headers={"Content-Type": "application/octet-stream"},
        )
        metrics["large_file_upload"] = _transfer_metrics(
            [large_upload],
            args.large_size,
            time.perf_counter() - large_upload_started,
        )

        propfind_results = [
            client.request(
                "PROPFIND",
                folder_path,
                body=propfind_body,
                headers={
                    "Content-Type": "application/xml; charset=utf-8",
                    "Depth": "1",
                },
            )
            for _ in range(args.latency_requests)
        ]
        metrics["folder_propfind"] = _latency_metrics(propfind_results)

        download_started = time.perf_counter()
        large_download = client.request("GET", f"{folder_path}/{large_name}")
        metrics["large_file_download"] = _transfer_metrics(
            [large_download],
            large_download.response_bytes,
            time.perf_counter() - download_started,
        )
        if large_download.response_bytes != args.large_size:
            raise BenchmarkError(
                "Downloadgroesse stimmt nicht: "
                f"{large_download.response_bytes} statt {args.large_size}"
            )
    finally:
        if folder_created and not args.keep_data:
            try:
                client.request("DELETE", folder_path)
            except BenchmarkError as cleanup_error:
                report["cleanup_error"] = str(cleanup_error)

    serialized = json.dumps(report, indent=2, sort_keys=True)
    if args.output:
        args.output.parent.mkdir(parents=True, exist_ok=True)
        args.output.write_text(serialized + "\n", encoding="utf-8")
    print(serialized)
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except BenchmarkError as error:
        print(f"Benchmark fehlgeschlagen: {error}", file=sys.stderr)
        raise SystemExit(1)
