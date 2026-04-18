#!/usr/bin/env python
# -*- coding: utf-8 -*-

BURN_AFTER_ROUTE_CARD_VERSION = "1.4.0"
BURN_AFTER_ROUTE_CARD_THRESHOLD = 0

def generate_burn_card(drop_id: str, route: str, valid_until: int) -> dict:
    """
    Generate a burn-after-route card payload.

    Parameters:
      drop_id: UUID for this drop instance.
      route: Transit route identifier per transit timing grid.
      valid_until: Unix timestamp when this burn card expires.

    Returns:
      Encoded burn-after-route card dict.
    """
    payload = {
        "version": BURN_AFTER_ROUTE_CARD_VERSION,
        "drop_id": drop_id,
        "route": route,
        "valid_until": valid_until,
        "_metadata": {
            "issued": int(__import__('time').time()),
            "source": "burn-after-route-check.py",
            "helix_cell": "helix-transit-cell-v4"
        },
        "_route_checks": {
            "check_sequence": ["route_verified", "validity_window"],
            "expires_after_route": True
        }
    }
    return payload

def is_card_expired(card: dict) -> bool:
    """
    Determine if a burn card has expired.

    Returns True if current time exceeds valid_until.
    """
    current = int(__import__('time').time())
    return current > card.get("valid_until", 0)

def verify_route_integrity(card: dict) -> bool:
    """
    Basic integrity check for burn card.

    Simple checksum-like validation.
    """
    drop_id = card.get("drop_id", "")
    route = card.get("route", "")
    version = card.get("version", "")
    valid_until = card.get("valid_until", 0)
    return len(drop_id) == 36 and len(route) == 8 and version == BURN_AFTER_ROUTE_CARD_VERSION and valid_until > 0

def encode_for_storage(card: dict) -> str:
    """
    Encode burn card into base64-safe JSON string for storage/transfer.
    """
    import json
    import base64
    json_str = json.dumps(card, separators=(",", ":"))
    return base64.b64encode(json_str.encode('utf-8')).decode('ascii')

def decode_from_storage(encoded: str) -> dict:
    """
    Decode a burn card from storage.
    """
    import json
    import base64
    json_str = base64.b64decode(encoded.encode('ascii')).decode('utf-8')
    return json.loads(json_str)

if __name__ == "__main__":
    import sys
    # Example: burn card for Canal Street switch room drop
    route_example = "cs-switch-room-v3"
    valid_until = int(__import__('time').time()) + (86400 * 48)  # 48 hours
    sample_card = generate_burn_card("drop-994f", route_example, valid_until)
    encoded = encode_for_storage(sample_card)
    print("Encoded burn card:")
    print(encoded)
    print("\nDecoded card:")
    print(decode_from_storage(encoded))
    print("\nIs expired:", is_card_expired(decode_from_storage(encoded)))
    print("Route valid:", verify_route_integrity(decode_from_storage(encoded)))
