import argparse
import json
import os
import re
from bisect import bisect_left
from collections import defaultdict
from dataclasses import dataclass
from datetime import datetime, timedelta
from typing import Any, Dict, List, Optional, Tuple

import requests


DATETIME_FMT = "%Y-%m-%d %H:%M:%S"
LOT_RE = re.compile(r"#(\d{1,4})\b")


@dataclass
class AuctionLot:
    lot_number: int
    order_id: str
    order_placed_at: datetime
    title: str


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Read-only re-alignment report for the 2026-02-27 auction."
    )
    parser.add_argument("--base-url", default=os.getenv("CARDGRAPH_BASE_URL"))
    parser.add_argument("--username", default=os.getenv("CARDGRAPH_USERNAME"))
    parser.add_argument("--password", default=os.getenv("CARDGRAPH_PASSWORD"))
    parser.add_argument("--session-id", type=int, default=16)
    parser.add_argument("--auction-date", default="2026-02-27")
    parser.add_argument("--livestream-title", default="MAGS AND SLABS $2 STARTS")
    parser.add_argument("--output-json", default="")
    parser.add_argument("--top", type=int, default=25)
    return parser.parse_args()


class ApiClient:
    def __init__(self, base_url: str, username: str, password: str) -> None:
        self.base_url = base_url.rstrip("/")
        self.session = requests.Session()

        resp = self.session.post(
            f"{self.base_url}/api/auth/login",
            json={"username": username, "password": password},
            timeout=30,
        )
        resp.raise_for_status()
        data = resp.json()
        csrf = data.get("csrf_token")
        if csrf:
            self.session.headers["X-CSRF-Token"] = csrf

    def get(self, path: str, **params: Any) -> Dict[str, Any]:
        resp = self.session.get(
            f"{self.base_url}{path}",
            params=params,
            timeout=60,
        )
        resp.raise_for_status()
        return resp.json()


def parse_dt(value: Optional[str]) -> Optional[datetime]:
    if not value:
        return None
    return datetime.strptime(value, DATETIME_FMT)


def pick_livestream(livestreams: List[Dict[str, Any]], date: str, title: str) -> Dict[str, Any]:
    exact = [
        item for item in livestreams
        if item.get("stream_date") == date and item.get("livestream_title") == title
    ]
    if exact:
        return exact[0]

    loose = [
        item for item in livestreams
        if item.get("stream_date") == date and title.lower() in item.get("livestream_title", "").lower()
    ]
    if loose:
        return loose[0]

    raise RuntimeError(f"No livestream found for {date} / {title}")


def fetch_all_line_items(client: ApiClient, livestream_id: str) -> List[Dict[str, Any]]:
    page = 1
    per_page = 100
    items: List[Dict[str, Any]] = []

    while True:
        payload = client.get(
            "/api/line-items",
            livestream_id=livestream_id,
            per_page=per_page,
            page=page,
            sort="order_placed_at",
            order="ASC",
        )
        batch = payload.get("data", [])
        items.extend(batch)
        if len(items) >= int(payload.get("total", 0)) or not batch:
            break
        page += 1

    return items


def build_auction_spine(items: List[Dict[str, Any]]) -> List[AuctionLot]:
    spine: List[AuctionLot] = []
    for item in items:
        if item.get("buy_format") != "AUCTION":
            continue
        title = item.get("listing_title") or ""
        if not title.startswith("BASEBALL SINGLES"):
            continue
        lot_match = LOT_RE.search(title)
        ts = parse_dt(item.get("order_placed_at"))
        if not lot_match or not ts:
            continue
        spine.append(
            AuctionLot(
                lot_number=int(lot_match.group(1)),
                order_id=str(item.get("order_id")),
                order_placed_at=ts,
                title=title,
            )
        )
    return sorted(spine, key=lambda item: item.lot_number)


def latest_complete_run(runs: List[Dict[str, Any]]) -> Dict[str, Any]:
    complete = [run for run in runs if run.get("status") == "complete"]
    if not complete:
        raise RuntimeError("No complete parse runs found")
    return sorted(complete, key=lambda item: int(item["run_id"]), reverse=True)[0]


def index_spine(spine: List[AuctionLot]) -> Tuple[Dict[str, int], Dict[int, AuctionLot], List[datetime], List[int]]:
    order_to_lot = {lot.order_id: lot.lot_number for lot in spine}
    lot_to_item = {lot.lot_number: lot for lot in spine}
    times = [lot.order_placed_at for lot in spine]
    lot_numbers = [lot.lot_number for lot in spine]
    return order_to_lot, lot_to_item, times, lot_numbers


def nearest_lot_by_time(target: Optional[datetime], times: List[datetime], lot_numbers: List[int]) -> Optional[int]:
    if not target or not times:
        return None
    idx = bisect_left(times, target)
    if idx <= 0:
        return lot_numbers[0]
    if idx >= len(times):
        return lot_numbers[-1]
    before = times[idx - 1]
    after = times[idx]
    if abs((target - before).total_seconds()) <= abs((after - target).total_seconds()):
        return lot_numbers[idx - 1]
    return lot_numbers[idx]


def detect_anchors(text: str) -> Dict[str, Any]:
    lowered = (text or "").lower()

    live_lots = set()
    preview_lots = set()
    structural = False
    price = False

    live_patterns = [
        r"\bwe(?:'re| are)\s+on\s+auction\s+(\d{1,4})\b",
        r"\bwe(?:'re| are)\s+on\s+(\d{1,4})\b",
        r"\bauction\s+number\s+(\d{1,4})\b",
        r"\blot\s+(?:number\s+)?(\d{1,4})\b",
    ]
    preview_patterns = [
        r"\bauction\s+(\d{1,4})\s+tonight\b",
        r"\brun\s+that\s+at\s+(\d{1,4})\b",
        r"\bcoming\s+up\s+at\s+(\d{1,4})\b",
        r"\bdo\s+one(?:\s+of\s+those)?\s+(?:pre-bid\s+auctions?\s+)?at\s+(\d{1,4})\b",
        r"\bfree\s+bids\s+on\s+auction\s+(\d{1,4})\b",
        r"\brun\s+(\d{1,4})\b",
    ]

    for pattern in live_patterns:
        for match in re.finditer(pattern, lowered):
            live_lots.add(int(match.group(1)))

    preview_phrases = ("pre-bid", "pre-bids", "coming up", "tonight", "later", "up next")
    for pattern in preview_patterns:
        for match in re.finditer(pattern, lowered):
            preview_lots.add(int(match.group(1)))

    if "back wall" in lowered:
        structural = True

    if re.search(r"\b\d+\s*(?:bucks|dollars)\b|\$\d+", lowered):
        price = True

    # Reclassify ambiguous numbers if preview language surrounds the text.
    if live_lots and any(token in lowered for token in preview_phrases):
        overlapping = {lot for lot in live_lots if lot in preview_lots}
        live_lots -= overlapping

    return {
        "live_lots": sorted(live_lots),
        "preview_lots": sorted(preview_lots),
        "structural": structural,
        "price": price,
    }


def build_segment_guardrails(
    records: List[Dict[str, Any]],
    spine_times: List[datetime],
    lot_numbers: List[int],
) -> Dict[int, Dict[str, int]]:
    by_segment: Dict[int, List[datetime]] = defaultdict(list)
    for record in records:
        seg = record.get("segment_number")
        ts = parse_dt(record.get("estimated_at"))
        if seg and ts:
            by_segment[int(seg)].append(ts)

    guardrails: Dict[int, Dict[str, int]] = {}
    for segment, timestamps in by_segment.items():
        if not timestamps:
            continue
        start = min(timestamps) - timedelta(seconds=60)
        end = max(timestamps) + timedelta(seconds=60)
        min_lot = nearest_lot_by_time(start, spine_times, lot_numbers)
        max_lot = nearest_lot_by_time(end, spine_times, lot_numbers)
        if min_lot is None or max_lot is None:
            continue
        guardrails[segment] = {"min": min(min_lot, max_lot), "max": max(min_lot, max_lot)}
    return guardrails


def lot_range(center: Optional[int], before: int, after: int) -> List[int]:
    if center is None:
        return []
    return list(range(max(1, center - before), center + after + 1))


def candidate_lots(
    record: Dict[str, Any],
    current_lot: Optional[int],
    anchors: Dict[str, Any],
    guardrail: Optional[Dict[str, int]],
    base_center: Optional[int],
    previous_assigned_lot: Optional[int],
    lot_to_item: Dict[int, AuctionLot],
) -> List[int]:
    candidates = set(lot_range(base_center, 8, 8))

    if current_lot is not None:
        candidates.update(lot_range(current_lot, 3, 3))

    for live_lot in anchors["live_lots"]:
        candidates.update(lot_range(live_lot, 3, 3))

    if previous_assigned_lot is not None:
        candidates.update(lot_range(previous_assigned_lot + 1, 2, 8))

    if guardrail:
        min_lot = max(1, guardrail["min"] - 10)
        max_lot = guardrail["max"] + 10
        candidates = {lot for lot in candidates if min_lot <= lot <= max_lot}

    return sorted(lot for lot in candidates if lot in lot_to_item)


def time_score(candidate_time: datetime, estimated_at: Optional[datetime]) -> int:
    if not estimated_at:
        return 0
    delta = abs((candidate_time - estimated_at).total_seconds())
    if delta <= 30:
        return 40
    if delta <= 60:
        return 34
    if delta <= 120:
        return 26
    if delta <= 180:
        return 18
    if delta <= 300:
        return 10
    return 0


def score_candidate(
    candidate_lot: int,
    candidate: AuctionLot,
    record: Dict[str, Any],
    current_lot: Optional[int],
    previous_assigned_lot: Optional[int],
    guardrail: Optional[Dict[str, int]],
    anchors: Dict[str, Any],
    base_center: Optional[int],
    recent_assigned: List[int],
) -> Tuple[int, List[str]]:
    reasons: List[str] = []
    score = 0

    estimate = parse_dt(record.get("estimated_at"))
    t_score = time_score(candidate.order_placed_at, estimate)
    score += t_score
    if t_score:
        reasons.append("time_window")

    if current_lot is not None and candidate_lot == current_lot:
        score += 8
        reasons.append("current_match_kept")

    if previous_assigned_lot is not None:
        if candidate_lot == previous_assigned_lot:
            score -= 12
            reasons.append("duplicate_lot_penalty")
        elif candidate_lot > previous_assigned_lot:
            gap = candidate_lot - previous_assigned_lot
            if gap <= 3:
                score += 16
                reasons.append("monotonic_sequence")
            elif gap <= 8:
                score += 10
                reasons.append("monotonic_sequence")
            elif gap <= 20:
                score += 2
            else:
                score -= 10
        else:
            backwards = previous_assigned_lot - candidate_lot
            if backwards <= 1:
                score -= 10
            else:
                score -= 25

    if guardrail:
        if guardrail["min"] <= candidate_lot <= guardrail["max"]:
            score += 12
        elif guardrail["min"] - 5 <= candidate_lot <= guardrail["max"] + 5:
            score += 4
        else:
            score -= 12
            reasons.append("outside_guardrail_penalty")

    if candidate_lot in anchors["live_lots"]:
        score += 30
        reasons.append("explicit_lot_anchor")
    elif anchors["live_lots"]:
        score -= 20

    if anchors["preview_lots"] and candidate_lot in anchors["preview_lots"]:
        if base_center is not None and abs(candidate_lot - base_center) > 8:
            score -= 15
            reasons.append("preview_only_do_not_jump")

    if anchors["structural"]:
        score += 4
        reasons.append("back_wall_structural")

    if anchors["price"]:
        score += 2
        reasons.append("price_confirmation")

    if candidate_lot in recent_assigned:
        score -= 15
        reasons.append("duplicate_lot_penalty")

    return score, sorted(set(reasons))


def compute_confidence(
    best_score: int,
    second_score: Optional[int],
    best_lot: int,
    guardrail: Optional[Dict[str, int]],
    anchors: Dict[str, Any],
) -> float:
    conf = 0.50
    if best_score >= 55:
        conf += 0.20
    elif best_score >= 45:
        conf += 0.10

    if second_score is None:
        conf += 0.15
    else:
        gap = best_score - second_score
        if gap >= 15:
            conf += 0.20
        elif gap >= 8:
            conf += 0.10

    if best_lot in anchors["live_lots"]:
        conf += 0.15

    if guardrail and not (guardrail["min"] - 5 <= best_lot <= guardrail["max"] + 5):
        conf -= 0.20

    if anchors["preview_lots"] and best_lot in anchors["preview_lots"] and not anchors["live_lots"]:
        conf -= 0.15

    return round(max(0.0, min(0.99, conf)), 2)


def build_reason_codes(
    reasons: List[str],
    current_lot: Optional[int],
    proposed_lot: Optional[int],
) -> List[str]:
    reason_codes = set(reasons)
    if proposed_lot is not None and current_lot is not None and proposed_lot != current_lot:
        reason_codes.add("drift_correction")
    return sorted(reason_codes)


def run_report(args: argparse.Namespace) -> Dict[str, Any]:
    if not args.base_url or not args.username or not args.password:
        raise RuntimeError("Set --base-url, --username, and --password (or CARDGRAPH_* env vars)")

    client = ApiClient(args.base_url, args.username, args.password)

    runs = client.get(f"/api/transcription/sessions/{args.session_id}/parse-runs")["data"]
    run = latest_complete_run(runs)
    records_payload = client.get(
        f"/api/transcription/sessions/{args.session_id}/records",
        run_id=run["run_id"],
    )
    transcript = client.get(f"/api/transcription/sessions/{args.session_id}/transcript-text")["segments"]
    livestreams = client.get("/api/livestreams")["data"]

    livestream = pick_livestream(livestreams, args.auction_date, args.livestream_title)
    line_items = fetch_all_line_items(client, livestream["livestream_id"])
    spine = build_auction_spine(line_items)
    if not spine:
        raise RuntimeError("No auction spine could be built")

    order_to_lot, lot_to_item, spine_times, lot_numbers = index_spine(spine)
    segment_text = {int(item["segment_number"]): item["text"] for item in transcript}
    guardrails = build_segment_guardrails(records_payload["data"], spine_times, lot_numbers)

    rows: List[Dict[str, Any]] = []
    previous_assigned_lot: Optional[int] = None
    recent_assigned: List[int] = []

    for record in records_payload["data"]:
        current_lot = None
        current_order_id = record.get("matched_order_id")
        if current_order_id is not None:
            current_lot = order_to_lot.get(str(current_order_id))

        seg_no = int(record["segment_number"]) if record.get("segment_number") else None
        seg_text = segment_text.get(seg_no, "")
        excerpt = record.get("raw_text_excerpt") or ""
        excerpt_anchors = detect_anchors(excerpt)
        segment_anchors = detect_anchors(seg_text)
        anchors = {
            "live_lots": excerpt_anchors["live_lots"],
            "preview_lots": sorted(set(excerpt_anchors["preview_lots"]) | set(segment_anchors["preview_lots"])),
            "structural": excerpt_anchors["structural"] or segment_anchors["structural"],
            "price": excerpt_anchors["price"],
        }
        estimate = parse_dt(record.get("estimated_at"))
        base_center = nearest_lot_by_time(estimate, spine_times, lot_numbers)
        guardrail = guardrails.get(seg_no) if seg_no is not None else None
        candidates = candidate_lots(
            record=record,
            current_lot=current_lot,
            anchors=anchors,
            guardrail=guardrail,
            base_center=base_center,
            previous_assigned_lot=previous_assigned_lot,
            lot_to_item=lot_to_item,
        )

        scored: List[Dict[str, Any]] = []
        for lot in candidates:
            score, reasons = score_candidate(
                candidate_lot=lot,
                candidate=lot_to_item[lot],
                record=record,
                current_lot=current_lot,
                previous_assigned_lot=previous_assigned_lot,
                guardrail=guardrail,
                anchors=anchors,
                base_center=base_center,
                recent_assigned=recent_assigned[-3:],
            )
            scored.append({"lot": lot, "score": score, "reasons": reasons})

        scored.sort(key=lambda item: item["score"], reverse=True)
        best = scored[0] if scored else None
        second = scored[1] if len(scored) > 1 else None

        if not best or best["score"] < 20:
            proposed_lot = None
            confidence = 0.0
            status = "orphaned"
            reasons = ["no_viable_candidate"]
        else:
            proposed_lot = int(best["lot"])
            confidence = compute_confidence(
                best_score=int(best["score"]),
                second_score=int(second["score"]) if second else None,
                best_lot=proposed_lot,
                guardrail=guardrail,
                anchors=anchors,
            )
            reasons = build_reason_codes(best["reasons"], current_lot, proposed_lot)

            if confidence < 0.85:
                status = "uncertain"
            elif "duplicate_lot_penalty" in reasons and proposed_lot != current_lot:
                status = "uncertain"
            elif current_lot == proposed_lot:
                status = "same"
            else:
                status = "corrected"

        if proposed_lot is not None:
            previous_assigned_lot = proposed_lot
            recent_assigned.append(proposed_lot)

        rows.append(
            {
                "sequence_number": int(record["sequence_number"]),
                "segment_number": seg_no,
                "estimated_at": record.get("estimated_at"),
                "player_name": record.get("player_name"),
                "current_lot": current_lot,
                "proposed_lot": proposed_lot,
                "delta": None if current_lot is None or proposed_lot is None else proposed_lot - current_lot,
                "confidence": confidence,
                "status": status,
                "reason_codes": reasons,
                "anchor_live_lots": anchors["live_lots"],
                "anchor_preview_lots": anchors["preview_lots"],
                "candidate_count": len(candidates),
            }
        )

    summary = {
        "session_id": args.session_id,
        "auction_date": args.auction_date,
        "livestream_id": livestream["livestream_id"],
        "livestream_title": livestream["livestream_title"],
        "run_id": int(run["run_id"]),
        "auction_lot_count": len(spine),
        "parsed_record_count": len(rows),
        "records_with_current_lot": sum(1 for row in rows if row["current_lot"] is not None),
        "same_count": sum(1 for row in rows if row["status"] == "same"),
        "corrected_count": sum(1 for row in rows if row["status"] == "corrected"),
        "uncertain_count": sum(1 for row in rows if row["status"] == "uncertain"),
        "orphaned_count": sum(1 for row in rows if row["status"] == "orphaned"),
    }

    return {
        "summary": summary,
        "rows": rows,
    }


def print_report(report: Dict[str, Any], top_n: int) -> None:
    summary = report["summary"]
    rows = report["rows"]

    print("Re-alignment report")
    print(json.dumps(summary, indent=2))
    print()

    corrected = [row for row in rows if row["status"] == "corrected"]
    uncertain = [row for row in rows if row["status"] == "uncertain"]
    big_drifts = [
        row for row in rows
        if row["current_lot"] is not None and row["proposed_lot"] is not None and abs(row["delta"]) >= 5
    ]

    print(f"Top {min(top_n, len(corrected))} corrected rows")
    for row in corrected[:top_n]:
        print(
            f"seq={row['sequence_number']:>4} seg={str(row['segment_number']):>3} "
            f"current={str(row['current_lot']):>4} proposed={str(row['proposed_lot']):>4} "
            f"conf={row['confidence']:.2f} player={row['player_name'] or '-'} "
            f"reasons={','.join(row['reason_codes'])}"
        )
    print()

    print(f"Top {min(top_n, len(big_drifts))} largest proposed drifts")
    for row in sorted(big_drifts, key=lambda item: abs(item["delta"]), reverse=True)[:top_n]:
        print(
            f"seq={row['sequence_number']:>4} seg={str(row['segment_number']):>3} "
            f"current={str(row['current_lot']):>4} proposed={str(row['proposed_lot']):>4} "
            f"delta={row['delta']:>4} conf={row['confidence']:.2f} "
            f"reasons={','.join(row['reason_codes'])}"
        )
    print()

    print(f"Top {min(top_n, len(uncertain))} uncertain rows")
    for row in uncertain[:top_n]:
        print(
            f"seq={row['sequence_number']:>4} seg={str(row['segment_number']):>3} "
            f"current={str(row['current_lot']):>4} proposed={str(row['proposed_lot']):>4} "
            f"conf={row['confidence']:.2f} player={row['player_name'] or '-'} "
            f"anchors_live={row['anchor_live_lots']} anchors_preview={row['anchor_preview_lots']}"
        )


def main() -> None:
    args = parse_args()
    report = run_report(args)

    if args.output_json:
        with open(args.output_json, "w", encoding="utf-8") as handle:
            json.dump(report, handle, indent=2)

    print_report(report, args.top)


if __name__ == "__main__":
    main()
