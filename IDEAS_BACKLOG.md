# JourneyAI — Ideas Backlog

Running list of feature ideas. References are inspiration for the *feel*, not literal specs.
All features stay offline-capable (no paid API keys) unless the user opts in.

## Done
- [x] ~93 destinations (India-heavy, web-researched real data) + 1,500-journal corpus (cost R² 0.75)
- [x] Unified JourneyAI editorial theme across ALL pages (no more Multikart admin look)
- [x] Dashboard-first flow; New Entry / My Entries / Profile / auth rebuilt in theme
- [x] Cinematic auto-carousel hero, transparent nav, teal accent, curated local photos, SVG icons
- [x] Theme toggle fixed; empty-results fix; best-season per destination
- [x] **Day-by-day itinerary planner** (ml/itinerary + /itinerary + itinerary.php)
- [x] **Versatile nearby places (OSM)** — Nominatim geocode + Overpass POI; works for ANY typed place
      (Anand → Borsad Stepwell, Lothal…). Trip modes (day/weekend/short/long). /nearby endpoint.
- [x] **Near me** — browser geolocation + reverse-geocode fills the origin field.
- [x] **Multi-destination route builder** — /route (nearest-neighbour) + route.php (timeline + live
      Leaflet OSM map + total km). Works for any place.
- [x] **Google Maps links** — all "open in maps" links use google.com/maps (route map stays Leaflet).

## In progress
- [ ] **Analytics realism** — personal insights (your spend/trips vs community) + practical insights
      (best value, cheapest months, cost ranges) + cleaner presentation. Backend endpoints added
      (/analytics/summary extended + /analytics/personal); page rebuild pending.

## NEXT BIG FEATURE — Social travel feed (user request)
Turn journals into shareable social content people can browse:
- [ ] **Photo + video upload** on New Entry (needs DB: media table; store files under uploads/).
- [ ] **Reels-style posts** — short vertical media + caption + destination/place tag.
- [ ] **Hashtags** — extract/allow #tags; popular/trending hashtags; tag pages.
- [ ] **Browse/discover feed** — public feed of user posts (grid + reel viewer), filter by hashtag/place.
- [ ] **Place images from user entries** — on nearby/place cards, show a real user photo tagged
      "User Entry" when one exists for that place; else a representative/auto photo; else gradient.
- Scope note: this is a substantial subsystem (uploads, media handling, feed UI, moderation-lite).
  Build in stages: upload → media on entries/cards → feed → reels/hashtags.

## Notes
- Backend flags destinations that have real (non-seed) user journals (ja_has_user_entry).
- Video upload = storage + size limits; keep local (uploads/) for the demo.
