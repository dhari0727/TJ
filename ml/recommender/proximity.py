"""
JourneyAI — region proximity model.

The catalog groups destinations into coarse regions. For the "near me / close to
home" preference, we score how accessible each destination's region is from the
user's chosen origin region, using a hand-built adjacency (0 = same region,
higher = farther). Converted to a proximity score in [0,1] where 1 = closest.

Origin regions the user can pick (mapped to catalog regions):
    North India, West India, South India, East India, Central India,
    Northeast India, Himalayas  (Indian origins)
    + a generic 'International' bucket.
"""

# canonical origin regions offered to the user
ORIGIN_REGIONS = [
    "North India", "West India", "South India", "East India",
    "Central India", "Northeast India", "Himalayas",
]

# coarse travel "distance" between regions (0 same, 1 neighbour, 2 far, 3 very far).
# International destinations get a fixed moderate distance from any Indian origin.
_ADJ = {
    "North India":     {"North India":0,"Himalayas":1,"West India":1,"Central India":1,"East India":2,"South India":3,"Northeast India":3},
    "West India":      {"West India":0,"North India":1,"Central India":1,"South India":2,"Himalayas":2,"East India":2,"Northeast India":3},
    "South India":     {"South India":0,"Central India":2,"West India":2,"East India":2,"North India":3,"Himalayas":3,"Northeast India":3},
    "East India":      {"East India":0,"Northeast India":1,"Central India":1,"North India":2,"South India":2,"Himalayas":2,"West India":2},
    "Central India":   {"Central India":0,"North India":1,"West India":1,"East India":1,"South India":2,"Himalayas":2,"Northeast India":2},
    "Northeast India": {"Northeast India":0,"East India":1,"Himalayas":2,"Central India":2,"North India":3,"West India":3,"South India":3},
    "Himalayas":       {"Himalayas":0,"North India":1,"East India":2,"Central India":2,"Northeast India":2,"West India":2,"South India":3},
}

# international / non-Indian regions -> treated as far for any Indian origin
_INTL_REGIONS = {"Southeast Asia","Middle East","Europe","East Asia","Caucasus",
                 "South Asia"}  # South Asia (Nepal/SL) is nearer but still cross-border

_MAX_DIST = 3.0


def proximity_score(origin_region, dest_region):
    """Return proximity in [0,1]; 1 = same region, lower = farther. None origin -> neutral 0.5."""
    if not origin_region or origin_region not in _ADJ:
        return 0.5
    if dest_region in _ADJ[origin_region]:
        dist = _ADJ[origin_region][dest_region]
    elif dest_region == "South Asia":
        dist = 2.5           # neighbouring countries: closer than long-haul intl
    elif dest_region in _INTL_REGIONS:
        dist = 3.0           # long-haul international
    else:
        dist = 2.0           # unknown -> mid
    return round(1.0 - (dist / _MAX_DIST), 3)


def distance_label(origin_region, dest_region):
    """Human label for the UI: 'Nearby', 'Same region', 'Cross-country', 'International'."""
    if dest_region in _INTL_REGIONS or dest_region == "South Asia":
        return "International"
    if not origin_region or origin_region not in _ADJ:
        return ""
    if dest_region == origin_region:
        return "In your region"
    d = _ADJ[origin_region].get(dest_region, 2)
    return "Nearby" if d == 1 else ("A short hop" if d == 2 else "Far side of India")
