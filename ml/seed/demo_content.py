"""
JourneyAI — DEMO community content (users, journals, posts, storybooks, likes, comments), Gujarat-local.

Why: the Community pages (Feed, Read journals, profiles) are empty on a fresh install. This fills them with believable
demo content so the product can be shown and tested.

Honesty and safety rules baked in:
  * every demo row is tagged with the e-mail domain @demo.journeyai, so it is easy to find and remove
  * demo accounts have NO usable password (nobody can log in as them); display names are neutral handles, not real people
  * images are generated illustrations ("Demo postcard" printed on them), not fake photographs; videos are short
    pan-and-zoom clips made from those illustrations
  * demo content is EXCLUDED from model training and from the admin "real users" counts
  * counts of likes/comments are real rows made by other demo accounts (nothing is a made-up number)

    ml/venv/Scripts/python.exe -m ml.seed.demo_content            # create (replaces any earlier demo content)
    ml/venv/Scripts/python.exe -m ml.seed.demo_content --remove   # delete all demo content and its files
"""
import argparse
import math
import os
import random
import secrets
import shutil
import subprocess
import sys
from datetime import datetime, timedelta

from PIL import Image, ImageDraw, ImageFilter, ImageFont

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
sys.path.insert(0, ROOT)
from ml.db import get_connection  # noqa: E402

DOMAIN = "@demo.journeyai"
UP = os.path.join(ROOT, "uploads", "demo")
W, H = 1200, 800
NOW = datetime.now()

# ----------------------------------------------------------------------------------------------- places
# kind drives the illustration; costs are whole-trip, per person, in rupees (food, transport, stay, activities, shopping)
PLACES = [
    dict(key="dwarka", name="Dwarka", state="Gujarat", kind="temple", pal=("#f6b26b", "#c0504d", "#3b2f4a"),
         spots="Dwarkadhish Temple, Gomti Ghat, Bet Dwarka, Nageshwar Jyotirlinga, Rukmini Temple", days=3, mode="train",
         hotel="Gomti Ghat guest house", cost=(2100, 3400, 2600, 600, 700),
         story="Reached Dwarka by the early train and went straight to Gomti Ghat for the morning aarti. The Dwarkadhish temple "
               "flag changes several times a day and watching it go up against the sea wind is something photos do not capture. "
               "We took the ferry to Bet Dwarka the next morning, crowded but worth it, and ate simple thali lunches near the jetty. "
               "Nageshwar on the way back was quiet and the stone work is lovely. Go early, keep your phone in a pouch at the "
               "temple, and carry cash for the ferry and the small shops."),
    dict(key="somnath", name="Somnath", state="Gujarat", kind="temple", pal=("#f9d29d", "#5aa9c8", "#1d3557"),
         spots="Somnath Temple, Triveni Sangam, Bhalka Tirth, Prabhas Patan museum", days=2, mode="car",
         hotel="Temple trust dharamshala", cost=(1400, 2600, 1800, 300, 400),
         story="Somnath at sunrise is calm in a way I did not expect. The temple stands right on the shore and the sound of the "
               "waves mixes with the bells. The evening light-and-sound show tells the history well. We walked to Triveni Sangam "
               "and stopped at Bhalka Tirth on the way to Diu. Rooms in the trust guest house are basic but clean and cost very "
               "little. Book at least two weeks ahead for weekends and festival days."),
    dict(key="junagadh", name="Junagadh", state="Gujarat", kind="fort", pal=("#ffd8a8", "#b5651d", "#2d3142"),
         spots="Uparkot Fort, Girnar hill, Mahabat Maqbara, Ashoka rock edict, Damodar Kund", days=3, mode="train",
         hotel="Railway-road lodge", cost=(1800, 2200, 2400, 500, 500),
         story="Junagadh feels like three cities in one: the Buddhist caves and Uparkot fort, the Nawabi buildings in the old town "
               "and the Girnar pilgrimage. We started the 9,999 steps at 5 am to beat the heat and it took us nearly five hours "
               "up and down. Legs were jelly for a day. The Mahabat Maqbara is the photo spot, and the street food near the "
               "clock tower, especially the local sweets, was a pleasant surprise."),
    dict(key="gir", name="Sasan Gir", state="Gujarat", kind="forest", pal=("#ffe29a", "#3a7d44", "#1b3a2d"),
         spots="Gir National Park, Devalia safari park, Kamleshwar dam, Tulsishyam", days=3, mode="car",
         hotel="Forest-side resort", cost=(2600, 3800, 5200, 4200, 500),
         story="We booked the early morning jeep safari three weeks in advance and still got the last permits. Saw a lioness with "
               "two cubs near a water hole after almost two hours of waiting, plus spotted deer, sambar and a lot of birds. The "
               "guides know the forest very well and keep a respectful distance. Evenings are cool and the resort cooks "
               "simple Kathiawadi food. Carry a light jacket for the morning ride and do not forget your ID for the permit."),
    dict(key="diu", name="Diu", state="Daman and Diu", kind="beach", pal=("#ffd6a5", "#3f88c5", "#0b2545"),
         spots="Diu Fort, Nagoa beach, St Paul's Church, Naida caves, Gangeshwar temple", days=3, mode="car",
         hotel="Beachfront guest house", cost=(3000, 2600, 3600, 900, 900),
         story="Diu is slow in the best way. We rented cycles and rode between the fort, the church and the quiet beaches without "
               "any plan. Nagoa beach is busy in the evening but Gomtimata beach at sunrise was nearly empty. The Portuguese "
               "streets are colourful and the seafood is excellent. Sunsets from the fort wall are worth the walk. Weekdays are "
               "much calmer and cheaper than weekends."),
    dict(key="rann", name="White Rann of Kutch", state="Gujarat", kind="desert", pal=("#fde2e4", "#e5989b", "#355070"),
         spots="White Rann, Kalo Dungar, Mandvi beach, Bhujodi weaving village, Aina Mahal", days=4, mode="car",
         hotel="Tent city", cost=(3200, 5200, 9000, 1500, 2500),
         story="Full-moon night at the White Rann is hard to describe: salt flats glowing under the moon and absolute silence "
               "away from the cultural stage. Days are hot, nights are cold, so pack layers. We drove from Bhuj via Kalo Dungar "
               "for the sunset and spent a morning in the Bhujodi weaving village. The tent city is the expensive part of the "
               "trip, so split it among four people and book early during the festival."),
    dict(key="ahmedabad", name="Ahmedabad", state="Gujarat", kind="city", pal=("#f7d6a8", "#d1495b", "#30323d"),
         spots="Manek Chowk, Sabarmati Ashram, Adalaj stepwell, Sidi Saiyyed jali, Kankaria lake", days=2, mode="train",
         hotel="Old city heritage stay", cost=(1900, 900, 2800, 400, 800),
         story="The heritage walk in the old city at 8 am is the best two hours you can spend in Ahmedabad. Pols, carved "
               "havelis and bird feeders everywhere. Manek Chowk after 9 pm turns into a food street, so come hungry. "
               "Sabarmati Ashram is peaceful and free, and Adalaj stepwell near sunset is spectacular. We used autos and the BRTS "
               "bus, which kept the transport bill tiny."),
    dict(key="nadiad", name="Nadiad", state="Gujarat", kind="temple", pal=("#fbc490", "#bc6c25", "#283618"),
         spots="Santram Mandir, Mai Mandir, Swaminarayan temple, Dakor Ranchhodrai", days=1, mode="bike",
         hotel="Day trip", cost=(450, 300, 0, 100, 150),
         story="A relaxed day trip from home: Santram Mandir first for the calm courtyard, then the Mai Mandir, and Dakor "
               "Ranchhodrai in the afternoon where the evening darshan queue moves quickly on weekdays. The local "
               "bhajiya and tea near the bus stand were the real highlight. Easy to do on a bike in a day and costs almost nothing."),
    dict(key="pavagadh", name="Pavagadh and Champaner", state="Gujarat", kind="hill", pal=("#cde7b0", "#6a994e", "#386641"),
         spots="Pavagadh Mahakali temple, Champaner heritage ruins, Jami Masjid, Hathi Khana", days=2, mode="train",
         hotel="Hill-base lodge", cost=(1200, 1400, 1600, 500, 200),
         story="Take the ropeway up Pavagadh if the queue is short, the view of the plains from the top is wide and green after "
               "the monsoon. The Champaner mosques are UNESCO listed and almost empty, so you can take your time with the stone "
               "carving. Bring water and a cap because the ruins have very little shade."),
    dict(key="kevadia", name="Statue of Unity, Kevadia", state="Gujarat", kind="city", pal=("#bde0fe", "#2a6f97", "#012a4a"),
         spots="Statue of Unity, Valley of Flowers, Sardar Sarovar dam view, Jungle safari", days=2, mode="car",
         hotel="Tent city Narmada", cost=(1600, 2800, 3800, 1800, 300),
         story="The statue is bigger than any photo prepares you for. Book the viewing gallery slot online and reach early "
               "because the queues build up by noon. The Valley of Flowers and the evening laser show were good extras. Roads "
               "around Narmada are smooth, so the drive from Vadodara is easy and takes under two hours."),
    dict(key="saputara", name="Saputara", state="Gujarat", kind="hill", pal=("#d8e2dc", "#52796f", "#2f3e46"),
         spots="Sunset point, Gira falls, Lake boating, Rose garden, Vansda national park", days=2, mode="car",
         hotel="Lake-view hotel", cost=(1500, 1900, 3200, 600, 300),
         story="Saputara is the easy monsoon escape: misty ghats, full waterfalls and cool evenings. Gira falls was at its best "
               "in August. Weekends are packed so we went mid-week and had the lake almost to ourselves. Rooms are priced by "
               "the weekend, so a Tuesday check-in saved a lot."),
    dict(key="palitana", name="Palitana", state="Gujarat", kind="temple", pal=("#ffe5b4", "#e07a5f", "#3d405b"),
         spots="Shatrunjaya hill Jain temples, Bhavnagar old town, Velavadar blackbuck park", days=2, mode="train",
         hotel="Dharamshala at the hill base", cost=(900, 1300, 1000, 200, 150),
         story="3,500 steps and more than 800 marble temples, the climb starts before sunrise and it is silent except for chants. "
               "Carry water, wear soft shoes and respect the rules about leather and food. The top at golden hour is "
               "one of the most beautiful sights in Gujarat. From Bhavnagar we also did a half-day at Velavadar to see blackbuck."),
    dict(key="modhera", name="Modhera and Patan", state="Gujarat", kind="temple", pal=("#ffd7a8", "#c1666b", "#2b2d42"),
         spots="Modhera Sun Temple, Rani ki Vav, Patola weaving workshop, Sahastralinga lake", days=1, mode="car",
         hotel="Day trip", cost=(600, 1400, 0, 200, 600),
         story="Rani ki Vav is the most beautiful stepwell I have seen: every wall is carved, and in the morning light the "
               "stone turns gold. Modhera Sun Temple is 40 minutes away and is lovely in the late afternoon. We stopped at a "
               "Patola weaving workshop in Patan and learned why one sari takes months. Easy day trip from Ahmedabad."),
    dict(key="bhuj", name="Bhuj", state="Gujarat", kind="fort", pal=("#ffe8d6", "#cb997e", "#6b705c"),
         spots="Aina Mahal, Prag Mahal, Kutch museum, Hamirsar lake, Bhujodi", days=2, mode="train",
         hotel="Old-town guest house", cost=(1300, 1500, 2000, 300, 900),
         story="Bhuj rebuilt itself after the 2001 earthquake and the old palaces still stand with their cracks as part of the "
               "story. Aina Mahal's hall of mirrors is the highlight. We ate dabeli at the street stalls in the evening and "
               "picked up block-printed fabric in Bhujodi. A good base for the Rann or Mandvi trips."),
]

USER_A = ["Sunrise", "Coastal", "Temple", "Chai", "Highway", "Pilgrim", "Monsoon", "Dune", "Fort", "Lakeside", "Ghat", "Street",
          "Wanderlust", "Backroad", "Mellow", "Salt", "Heritage", "Trail", "Weekend", "Slow"]
USER_B = ["Wanderer", "Trails", "Notes", "Diaries", "Roamer", "Miles", "Stories", "Frames", "Route", "Escapes", "Tales", "Compass"]
BIOS = ["Weekend wanderer from Gujarat. Temples, forts and chai.", "Slow travel, local food, long train rides.",
        "Photographing old stepwells and sunsets.", "Budget trips across Saurashtra and Kutch.",
        "Road trips with family. Always carrying snacks.", "Exploring India one thali at a time.",
        "Trekking when possible, napping when not.", "Collecting small towns and big sunsets."]

COMMENTS_GENERIC = ["Beautiful shot!", "This is on my list for next month.", "Great tips, thank you for sharing.", "Loved reading this.",
                    "How crowded was it on a weekday?", "The colours here are unreal.", "Saving this for my next trip.", "Wow. What time did you reach?",
                    "Super helpful, especially the cost part.", "Took the same route last year, such a good trip.",
                    "Did you need to book in advance?", "Brings back memories.", "Which month would you suggest?", "Perfect weekend plan.",
                    "Your photos make me want to leave right now.", "Thanks! Going this weekend.", "Underrated place for sure.", "Nice one!"]
COMMENTS_PLACE = {
    "temple": ["The aarti timing tip was very useful.", "Is photography allowed inside?", "Peaceful place, we went early morning too."],
    "beach": ["How was the water in November?", "Cycling around Diu is the best idea.", "Nagoa at sunrise sounds perfect."],
    "desert": ["Is the full-moon night worth the tent price?", "We went in December, freezing at night!", "What did the tent stay cost per person?"],
    "forest": ["Did you see lions on the first safari?", "Permits sell out so fast, good that you booked early.", "Amazing to see cubs!"],
    "fort": ["The old town food scene is so underrated.", "How many hours do you need here?", "Love the heritage buildings."],
    "hill": ["Monsoon is the best time for this.", "Did the ropeway run all day?", "Great view, thanks for the tip about weekdays."],
    "city": ["The food walk is a must.", "Auto or BRTS to get around?", "Tried Manek Chowk last month, so good."],
}
CAPTIONS = {
    "temple": ["Morning aarti and the sound of bells #{tag} #temples #gujarat", "Early darshan, no queue at all #{tag} #gujarat #travel",
               "Flag on the spire against the sky #{tag} #pilgrimage", "Quiet courtyard before the crowds arrive #{tag} #heritage"],
    "beach": ["Slow sunsets and sea breeze #{tag} #beach #diu", "Cycling between beaches all day #{tag} #slowtravel",
              "Empty beach at sunrise #{tag} #beach #gujarat"],
    "desert": ["Salt flats under the full moon #{tag} #rannutsav #kutch", "Silence like nothing else #{tag} #kutch #desert",
               "Sunset at the edge of the white desert #{tag} #photography"],
    "forest": ["Waiting two hours for this moment #{tag} #girforest #wildlife", "Golden hour in the dry deciduous forest #{tag} #safari",
               "Morning mist and sambar deer #{tag} #nature"],
    "fort": ["Old walls and new stories #{tag} #fort #heritage", "Hall of mirrors and winding lanes #{tag} #history #gujarat",
             "Climbing the stairs at sunrise #{tag} #travel"],
    "hill": ["Monsoon mist all around #{tag} #monsoon #hills", "Green after the rains #{tag} #nature #weekend",
             "Waterfall full to the brim #{tag} #gujarat"],
    "city": ["Street food at night #{tag} #foodie #gujarat", "Heritage walk in the old city #{tag} #heritage #walk",
             "Evening lights and a long day #{tag} #citylife"],
}


# ----------------------------------------------------------------------------------------------- illustrations
def _font(size, bold=False):
    for f in (("arialbd.ttf" if bold else "arial.ttf"), ("segoeuib.ttf" if bold else "segoeui.ttf"), "DejaVuSans.ttf"):
        try:
            return ImageFont.truetype(f, size)
        except OSError:
            continue
    return ImageFont.load_default()


def _hex(c):
    c = c.lstrip("#")
    return tuple(int(c[i:i + 2], 16) for i in (0, 2, 4))


def _grad(img, top, bottom):
    d = ImageDraw.Draw(img)
    for y in range(H):
        t = y / H
        d.line([(0, y), (W, y)], fill=tuple(int(top[i] + (bottom[i] - top[i]) * t) for i in range(3)))


def _jitter(c, rng, amt=14):
    return tuple(max(0, min(255, v + rng.randint(-amt, amt))) for v in c)


def postcard(place, variant, path, rng):
    """Draw a simple illustrated postcard for a place. variant tweaks time of day and layout."""
    top, mid, dark = (_jitter(_hex(c), rng) for c in place["pal"])
    night = variant % 3 == 2
    img = Image.new("RGB", (W, H))
    _grad(img, tuple(int(v * 0.35) for v in dark) if night else top, dark if night else mid)
    d = ImageDraw.Draw(img, "RGBA")
    # sun / moon and soft glow
    sx, sy = rng.randint(200, 1000), rng.randint(130, 300)
    r = rng.randint(55, 85)
    glow = (255, 250, 230) if night else (255, 236, 179)
    for k in range(8, 0, -1):
        d.ellipse([sx - r - k * 14, sy - r - k * 14, sx + r + k * 14, sy + r + k * 14], fill=glow + (9,))
    d.ellipse([sx - r, sy - r, sx + r, sy + r], fill=glow + (255,))
    if night:
        for _ in range(70):
            x, y = rng.randint(0, W), rng.randint(0, 380)
            d.ellipse([x, y, x + 3, y + 3], fill=(255, 255, 255, rng.randint(120, 255)))
    ground = tuple(max(0, int(v * 0.55)) for v in dark)
    kind = place["kind"]
    base_y = 560
    if kind == "temple":
        cx = rng.randint(420, 780)
        for i, (w, h) in enumerate([(300, 40), (250, 40), (200, 50), (150, 60), (100, 60), (60, 50)]):
            d.rectangle([cx - w // 2, base_y - sum(x[1] for x in [(300, 40), (250, 40), (200, 50), (150, 60), (100, 60), (60, 50)][:i + 1]),
                         cx + w // 2, base_y - sum(x[1] for x in [(300, 40), (250, 40), (200, 50), (150, 60), (100, 60), (60, 50)][:i])], fill=ground)
        top_y = base_y - 300
        d.polygon([(cx - 30, top_y), (cx + 30, top_y), (cx, top_y - 70)], fill=ground)
        d.line([(cx, top_y - 70), (cx, top_y - 130)], fill=ground, width=5)
        d.polygon([(cx, top_y - 130), (cx + 60, top_y - 112), (cx, top_y - 95)], fill=(224, 87, 63, 255))
        for off in (-260, 260):
            d.polygon([(cx + off - 70, base_y), (cx + off + 70, base_y), (cx + off + 40, base_y - 120), (cx + off - 40, base_y - 120)], fill=ground)
    elif kind == "beach":
        for k in range(5):
            y0 = 520 + k * 55
            pts = [(x, y0 + 14 * math.sin(x / 70 + k * 1.3 + variant)) for x in range(0, W + 20, 20)]
            d.polygon(pts + [(W, H), (0, H)], fill=_jitter(_hex(place["pal"][1]), rng, 20) + (150 + k * 20,))
        bx = rng.randint(250, 900)
        d.polygon([(bx, 600), (bx + 130, 600), (bx + 100, 640), (bx + 30, 640)], fill=ground)
        d.line([(bx + 65, 600), (bx + 65, 500)], fill=ground, width=5)
        d.polygon([(bx + 65, 500), (bx + 125, 590), (bx + 65, 590)], fill=(255, 245, 235, 255))
    elif kind == "desert":
        for k in range(4):
            y0 = 470 + k * 70
            pts = [(x, y0 + 40 * math.sin(x / 190 + k * 2 + variant)) for x in range(0, W + 20, 20)]
            d.polygon(pts + [(W, H), (0, H)], fill=tuple(min(255, int(v * (0.85 + k * 0.1))) for v in _hex(place["pal"][0])) + (255,))
        for _ in range(3):  # little tent/camel-like markers
            tx = rng.randint(120, 1050)
            d.polygon([(tx, 600), (tx + 70, 600), (tx + 35, 545)], fill=ground)
    elif kind == "forest":
        for layer in range(3):
            shade = tuple(int(v * (0.5 + layer * 0.25)) for v in _hex(place["pal"][1]))
            for _ in range(11):
                tx = rng.randint(-30, W + 30)
                ty = 560 + layer * 40
                th = rng.randint(190, 330)
                d.polygon([(tx - 55, ty), (tx + 55, ty), (tx, ty - th)], fill=shade + (255,))
                d.rectangle([tx - 6, ty, tx + 6, ty + 40], fill=ground)
    elif kind == "fort":
        wall_top = 470
        d.rectangle([0, wall_top, W, H], fill=ground)
        for x in range(0, W, 60):
            d.rectangle([x, wall_top - 30, x + 34, wall_top], fill=ground)
        for tx in (rng.randint(150, 350), rng.randint(750, 1050)):
            d.rectangle([tx, wall_top - 190, tx + 110, wall_top], fill=ground)
            for x in range(tx, tx + 110, 30):
                d.rectangle([x, wall_top - 220, x + 18, wall_top - 190], fill=ground)
            d.polygon([(tx + 25, wall_top - 80), (tx + 55, wall_top - 130), (tx + 85, wall_top - 80)], fill=(255, 220, 150, 120))
    elif kind == "hill":
        for layer in range(3):
            shade = tuple(int(v * (0.6 + layer * 0.2)) for v in _hex(place["pal"][1]))
            pts = [(x, 470 + layer * 55 - 120 * abs(math.sin(x / 260 + layer * 1.7 + variant))) for x in range(0, W + 20, 20)]
            d.polygon(pts + [(W, H), (0, H)], fill=shade + (255,))
        for _ in range(4):
            wx = rng.randint(100, 1100)
            d.line([(wx, 430), (wx - 4, 520)], fill=(255, 255, 255, 190), width=6)
    else:  # city
        x = 0
        while x < W:
            bw = rng.randint(70, 140)
            bh = rng.randint(120, 330)
            d.rectangle([x, base_y - bh, x + bw, H], fill=ground)
            for wy in range(base_y - bh + 20, base_y - 10, 36):
                for wx in range(x + 12, x + bw - 14, 28):
                    if rng.random() < 0.55:
                        d.rectangle([wx, wy, wx + 12, wy + 18], fill=(255, 226, 150, 235 if night else 150))
            if rng.random() < 0.3:
                d.ellipse([x + bw // 2 - 22, base_y - bh - 30, x + bw // 2 + 22, base_y - bh + 14], fill=ground)
            x += bw + rng.randint(4, 14)
    d.rectangle([0, 640, W, H], fill=ground + (255,))
    img = img.filter(ImageFilter.GaussianBlur(0.6)).convert("RGB")
    d = ImageDraw.Draw(img, "RGBA")
    d.rectangle([0, 0, W, H], outline=(255, 255, 255, 200), width=14)
    for dx, dy, a in ((2, 2, 120), (0, 0, 255)):
        d.text((58 + dx, 670 + dy), place["name"], font=_font(78, True), fill=(255, 255, 255, a) if a == 255 else (0, 0, 0, a))
        d.text((62 + dx, 760 + dy), f"{place['state']}, India", font=_font(30), fill=(255, 255, 255, a) if a == 255 else (0, 0, 0, a))
    d.text((W - 300, H - 52), "Demo postcard (illustration)", font=_font(22), fill=(255, 255, 255, 200))
    img.save(path, "JPEG", quality=88)


def make_video(img_path, out_path, rng):
    """6-second pan and zoom clip from an illustration (ffmpeg)."""
    ff = shutil.which("ffmpeg") or r"C:\Users\HP\AppData\Local\Microsoft\WinGet\Links\ffmpeg.exe"
    z = rng.choice(["min(zoom+0.0012,1.25)", "if(eq(on,0),1.25,max(zoom-0.0012,1.0))"])
    cmd = [ff, "-y", "-loglevel", "error", "-loop", "1", "-i", img_path, "-vf",
           f"zoompan=z='{z}':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d=150:s=960x640:fps=25,format=yuv420p",
           "-t", "6", "-c:v", "libx264", "-preset", "veryfast", "-crf", "28", "-movflags", "+faststart", out_path]
    try:
        subprocess.run(cmd, check=True, timeout=120)
        return os.path.exists(out_path)
    except Exception as e:  # noqa: BLE001
        print("  (video skipped:", e, ")")
        return False


# ----------------------------------------------------------------------------------------------- database
def remove(conn):
    like = "%" + DOMAIN
    with conn.cursor() as c:
        for t, col in (("media_comments", "media_id"), ("media_likes", "media_id"), ("media_hashtags", "media_id")):
            c.execute(f"DELETE x FROM {t} x JOIN media m ON m.media_id = x.{col} WHERE m.eml LIKE %s", (like,))
        c.execute("DELETE FROM media_likes WHERE eml LIKE %s", (like,))
        c.execute("DELETE FROM media_comments WHERE eml LIKE %s", (like,))
        c.execute("DELETE FROM media WHERE eml LIKE %s", (like,))
        c.execute("DELETE FROM journal_likes WHERE eml LIKE %s", (like,))
        c.execute("DELETE FROM journal_comments WHERE eml LIKE %s", (like,))
        c.execute("DELETE jl FROM journal_likes jl JOIN db d ON d.entry_id = jl.entry_id WHERE d.eml LIKE %s", (like,))
        c.execute("DELETE jc FROM journal_comments jc JOIN db d ON d.entry_id = jc.entry_id WHERE d.eml LIKE %s", (like,))
        c.execute("DELETE sc FROM storybook_comments sc JOIN storybook_pages p ON p.page_id = sc.page_id JOIN storybooks b ON b.book_id = p.book_id WHERE b.eml LIKE %s", (like,))
        c.execute("DELETE FROM storybook_comments WHERE eml LIKE %s", (like,))
        c.execute("DELETE FROM storybook_likes WHERE eml LIKE %s", (like,))
        c.execute("DELETE sl FROM storybook_likes sl JOIN storybooks b ON b.book_id = sl.book_id WHERE b.eml LIKE %s", (like,))
        c.execute("DELETE FROM storybooks WHERE eml LIKE %s", (like,))
        for t in ("db1", "db2", "db3", "db", "user_prefs", "signup"):
            c.execute(f"DELETE FROM {t} WHERE eml LIKE %s", (like,))
        c.execute("UPDATE hashtags h SET uses = (SELECT COUNT(*) FROM media_hashtags mh WHERE mh.tag_id = h.tag_id)")
        c.execute("DELETE FROM hashtags WHERE uses = 0")
    conn.commit()
    shutil.rmtree(UP, ignore_errors=True)


def save_tags(c, media_id, caption):
    import re
    for t in sorted(set(x.lower() for x in re.findall(r"#([A-Za-z0-9_]{2,50})", caption))):
        c.execute("INSERT INTO hashtags (tag, uses) VALUES (%s,1) ON DUPLICATE KEY UPDATE uses = uses + 1", (t,))
        c.execute("SELECT tag_id FROM hashtags WHERE tag = %s", (t,))
        c.execute("INSERT IGNORE INTO media_hashtags (media_id, tag_id) VALUES (%s,%s)", (media_id, c.fetchone()["tag_id"]))


def skewed(rng, n_users, hi):
    """Like counts people actually see: most posts get a few, a few get many."""
    return min(n_users, max(0, int(rng.lognormvariate(1.6, 0.8)) + rng.randint(0, 2)) if hi else rng.randint(0, 3))


def money(x):
    """Rupee amount as a tidy whole-ten string (the legacy expense columns hold text)."""
    return str(int(round(float(x) / 10.0) * 10))


def unusable_hash():
    """A bcrypt-shaped string nobody knows a password for (password_verify() can never match it)."""
    alphabet = "./ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789"
    return "$2y$10$" + "".join(secrets.choice(alphabet) for _ in range(53))


def at(days_ago, rng):
    return (NOW - timedelta(days=days_ago, hours=rng.randint(0, 23), minutes=rng.randint(0, 59))).strftime("%Y-%m-%d %H:%M:%S")


def later(ts, rng, max_days=6):
    t = datetime.strptime(ts, "%Y-%m-%d %H:%M:%S") + timedelta(days=rng.random() * max_days, minutes=rng.randint(5, 600))
    return min(t, NOW).strftime("%Y-%m-%d %H:%M:%S")


BOOKS = [
    dict(title="Saurashtra in five days", sub="Somnath, Dwarka, Junagadh and Gir by train and jeep", theme="retro-travel",
         places=["somnath", "dwarka", "junagadh", "gir"], tpl=["cover", "story", "photo-caption", "two-photo", "story", "cost-card", "photos"], public="public", pin=True),
    dict(title="Dwarka diaries", sub="Bells, ferries and one long sunset", theme="floral",
         places=["dwarka"], tpl=["cover", "story", "photo-caption", "photos", "cost-card"], public="public", pin=False),
    dict(title="Rann nights", sub="Full moon on the white desert", theme="moody-film",
         places=["rann"], tpl=["cover", "story", "two-photo", "photo-caption", "cost-card"], public="public", pin=False),
    dict(title="Gir: lions and chai", sub="Three days at the edge of the forest", theme="cottagecore",
         places=["gir"], tpl=["cover", "photo-caption", "story", "photos"], public="link", pin=False),
    dict(title="A food and heritage weekend", sub="Ahmedabad old city and a Nadiad day trip", theme="minimal",
         places=["ahmedabad", "nadiad"], tpl=["cover", "story", "photo-caption", "story", "cost-card"], public="public", pin=False),
]


def create(conn, seed=11):
    rng = random.Random(seed)
    remove(conn)
    os.makedirs(UP, exist_ok=True)
    c = conn.cursor()
    pk = {p["key"]: p for p in PLACES}

    # ---- 1. users (neutral handles, no usable password) -------------------------------------------------------
    names, users = set(), []
    while len(users) < 40:
        a, b = rng.choice(USER_A), rng.choice(USER_B)
        if (a, b) in names:
            continue
        names.add((a, b))
        handle = (a + b).lower() + "-" + secrets.token_hex(3)[:5]
        eml = handle + DOMAIN
        c.execute("INSERT INTO signup (fname, lname, eml, psw, role, is_active, created_at, handle, bio) VALUES (%s,%s,%s,%s,'user',1,%s,%s,%s)",
                  (a, b, eml, unusable_hash(), at(rng.randint(15, 220), rng), handle, rng.choice(BIOS)))
        users.append(eml)
    conn.commit()

    def other(author, k=1):
        pool = [u for u in users if u != author]
        return rng.sample(pool, k)

    # ---- 2. journals (each with 2-4 attached illustrations) ----------------------------------------------------
    journal_of, entries = {}, []
    visibilities = ["public"] * 11 + ["link", "private", "public"]
    for n, pl in enumerate(PLACES):
        author = users[n % len(users)] if n < 12 else rng.choice(users)
        dv_days = rng.randint(12, 150)
        dv = (NOW - timedelta(days=dv_days)).strftime("%Y-%m-%d")
        dr = (NOW - timedelta(days=dv_days - pl["days"])).strftime("%Y-%m-%d")
        title = rng.choice([f"{pl['days']} days in {pl['name']}", f"{pl['name']} on a budget", f"{pl['name']}: what it really cost",
                            f"Slow notes from {pl['name']}", f"{pl['name']} trip diary"])
        food, trans, stay, acts, shop = (int(v * rng.uniform(0.92, 1.1) / 10) * 10 for v in pl["cost"])
        meal = [food * f for f in (0.18, 0.3, 0.12, 0.34, 0.06)]
        mode = {"train": (0.1, 0.1, 0.65, 0.15), "car": (0.6, 0.3, 0.05, 0.05), "bike": (0.7, 0.05, 0.0, 0.25)}[pl["mode"]]
        tr = [trans * f for f in mode]
        vis = visibilities[n]
        token = secrets.token_urlsafe(16)[:22] if vis != "private" else None
        pub_at = later(dv + " 10:00:00", rng, 4) if vis == "public" else None
        c.execute("INSERT INTO db(Title,Description,Country,City,cd,dv,dr,hn,address,ptv,tv,budget_target,eml,visibility,share_token,published_at) "
                  "VALUES (%s,%s,'India',%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)",
                  (title, pl["story"], pl["name"], dv, dv, dr, pl["hotel"], f"{pl['name']}, {pl['state']}", pl["spots"],
                   pl["mode"], round((food + trans + stay + acts + shop) * 1.1, -2), author, vis, token, pub_at))
        entry_id = c.lastrowid
        d1 = [meal[0], meal[1], meal[2], meal[3], meal[4], tr[0], tr[1], tr[2], 0, 0, 0, tr[3], food, trans, title, author]
        c.execute("INSERT INTO db1 VALUES (" + ",".join(["%s"] * 16) + ")", [money(x) if isinstance(x, float) else x for x in d1])
        c.execute("INSERT INTO db2 VALUES (" + ",".join(["%s"] * 11) + ")", (stay, 0, 0, acts * 0.4, 0, 0, 0, 0, 0, title, author))
        c.execute("INSERT INTO db3 VALUES (" + ",".join(["%s"] * 17) + ")",
                  (acts * 0.6, 0, 0, 0, 0, shop, 0, 0, 0, 0, 0, 0, acts * 0.6, shop, title, food + trans + acts + shop, author))
        journal_of[pl["key"]] = (entry_id, author)
        entries.append((entry_id, author, vis, dv + " 10:00:00", pl))
        for v in range(rng.randint(2, 4)):
            f = f"{secrets.token_hex(8)}.jpg"
            postcard(pl, v + n, os.path.join(UP, f), rng)
            cap = rng.choice(CAPTIONS[pl["kind"]]).format(tag=pl["key"]) if v == 0 else f"{pl['name']}, day {v + 1}"
            c.execute("INSERT INTO media (entry_id, eml, kind, filepath, caption, destination, is_public, likes, created_at) VALUES (%s,%s,'photo',%s,%s,%s,%s,0,%s)",
                      (entry_id, author, "uploads/demo/" + f, cap, f"{pl['name']}, {pl['state']}", 0 if vis == "private" else 1, dv + " 12:00:00"))
            save_tags(c, c.lastrowid, cap)
    conn.commit()

    # ---- 3. standalone posts: photos and a few short videos -------------------------------------------------------
    posts = []
    for i in range(38):
        pl = PLACES[i % len(PLACES)] if i < 14 else rng.choice(PLACES)
        author = rng.choice(users)
        f = f"{secrets.token_hex(8)}.jpg"
        postcard(pl, i + 3, os.path.join(UP, f), rng)
        kind, path = "photo", "uploads/demo/" + f
        if i % 8 == 5:  # about one post in eight is a short video
            vf = f"{secrets.token_hex(8)}.mp4"
            if make_video(os.path.join(UP, f), os.path.join(UP, vf), rng):
                kind, path = "video", "uploads/demo/" + vf
        cap = rng.choice(CAPTIONS[pl["kind"]]).format(tag=pl["key"])
        ts = at(rng.randint(1, 75), rng)
        c.execute("INSERT INTO media (eml, kind, filepath, caption, destination, is_public, likes, created_at) VALUES (%s,%s,%s,%s,%s,1,0,%s)",
                  (author, kind, path, cap, f"{pl['name']}, {pl['state']}", ts))
        mid = c.lastrowid
        save_tags(c, mid, cap)
        posts.append((mid, author, ts, pl))
    conn.commit()

    # pins: about a fifth of the users pin one of their own posts
    c.execute("SELECT media_id, eml FROM media WHERE eml LIKE %s ORDER BY media_id", ("%" + DOMAIN,))
    seen = set()
    for r in c.fetchall():
        if r["eml"] not in seen and rng.random() < 0.3:
            seen.add(r["eml"])
            c.execute("UPDATE media SET is_pinned = 1 WHERE media_id = %s", (r["media_id"],))

    # ---- 4. likes and comments on posts and attached photos -----------------------------------------------------
    c.execute("SELECT media_id, eml, created_at, caption, destination, entry_id FROM media WHERE eml LIKE %s", ("%" + DOMAIN,))
    allm = c.fetchall()
    for m in allm:
        kind = next((p["kind"] for p in PLACES if m["destination"] and m["destination"].startswith(p["name"])), "city")
        n_likes = skewed(rng, len(users) - 1, True)
        for u in other(m["eml"], n_likes):
            c.execute("INSERT IGNORE INTO media_likes (media_id, eml, created_at) VALUES (%s,%s,%s)", (m["media_id"], u, later(str(m["created_at"]), rng)))
        c.execute("UPDATE media SET likes = (SELECT COUNT(*) FROM media_likes WHERE media_id = %s) WHERE media_id = %s", (m["media_id"], m["media_id"]))
        for _ in range(rng.choices([0, 1, 2, 3, 5, 7], [26, 26, 20, 14, 9, 5])[0]):
            u = rng.choice(users)
            text = rng.choice(COMMENTS_PLACE.get(kind, []) + COMMENTS_GENERIC)
            c.execute("INSERT INTO media_comments (media_id, eml, body, created_at) VALUES (%s,%s,%s,%s)", (m["media_id"], u, text, later(str(m["created_at"]), rng)))

    # ---- 5. likes and comments on shared journals -----------------------------------------------------------------
    for entry_id, author, vis, ts, pl in entries:
        if vis == "private":
            continue
        for u in other(author, skewed(rng, len(users) - 1, True) + 2):
            c.execute("INSERT IGNORE INTO journal_likes (entry_id, eml, created_at) VALUES (%s,%s,%s)", (entry_id, u, later(ts, rng, 20)))
        for _ in range(rng.choices([1, 2, 3, 4, 6], [25, 30, 25, 12, 8])[0]):
            c.execute("INSERT INTO journal_comments (entry_id, eml, body, created_at) VALUES (%s,%s,%s,%s)",
                      (entry_id, rng.choice(users), rng.choice(COMMENTS_PLACE.get(pl["kind"], []) + COMMENTS_GENERIC), later(ts, rng, 20)))
        if rng.random() < 0.25:
            c.execute("UPDATE db SET is_pinned = 1 WHERE entry_id = %s", (entry_id,))
    conn.commit()

    # ---- 6. storybooks with pages, likes and page comments -----------------------------------------------------------
    n_books = 0
    for b in BOOKS:
        author = journal_of[b["places"][0]][1]
        token = secrets.token_urlsafe(16)[:22] if b["public"] != "private" else None
        created = at(rng.randint(10, 90), rng)
        covers, page_rows = [], []
        c.execute("INSERT INTO storybooks (eml, title, subtitle, theme, visibility, share_token, page_order, created_at, updated_at, is_pinned) "
                  "VALUES (%s,%s,%s,%s,%s,%s,'manual',%s,%s,%s)", (author, b["title"], b["sub"], b["theme"], b["public"], token, created, created, int(b["pin"])))
        book_id = c.lastrowid
        for order, tpl in enumerate(b["tpl"]):
            pl = pk[b["places"][order % len(b["places"])]]
            def pic(v):
                f = f"{secrets.token_hex(8)}.jpg"
                postcard(pl, v + order, os.path.join(UP, f), rng)
                return "uploads/demo/" + f
            sents = [x.strip() + "." for x in pl["story"].split(".") if len(x.strip()) > 20]
            row = dict(template=tpl, title=None, body=None, date=None, p1=None, c1=None, p2=None, c2=None, p3=None, c3=None, p4=None, c4=None, cost=None)
            d_ = (NOW - timedelta(days=rng.randint(10, 90))).strftime("%Y-%m-%d")
            if tpl == "cover":
                row.update(title=b["title"], body=b["sub"], date=d_, p1=pic(1)); covers.append(row["p1"])
            elif tpl == "story":
                row.update(title=f"{pl['name']}", date=d_, body=" ".join(sents[:3]))
            elif tpl == "photo-caption":
                row.update(title=f"{pl['name']} in one frame", body=" ".join(sents[3:5]) or sents[0], p1=pic(2), c1=f"{pl['name']}, {pl['state']}")
            elif tpl == "two-photo":
                row.update(title=f"Two views of {pl['name']}", body=sents[1] if len(sents) > 1 else sents[0], p1=pic(3), c1="Morning", p2=pic(4), c2="Evening")
            elif tpl == "photos":
                row.update(title=f"{pl['name']}: the rest of the roll", p1=pic(5), c1="Arrival", p2=pic(6), c2="Lunch stop", p3=pic(7), c3="Golden hour", p4=pic(8), c4="Last look")
            elif tpl == "cost-card":
                row.update(title=f"What {pl['name']} cost", cost=journal_of[pl["key"]][0])
            page_rows.append(row)
        first_pages = []
        for order, r in enumerate(page_rows):
            c.execute("INSERT INTO storybook_pages (book_id, sort_order, template, title, body_text, page_date, photo_1, photo_1_cap, photo_2, photo_2_cap, "
                      "photo_3, photo_3_cap, photo_4, photo_4_cap, cost_entry_id) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)",
                      (book_id, order, r["template"], r["title"], r["body"], r["date"], r["p1"], r["c1"], r["p2"], r["c2"], r["p3"], r["c3"], r["p4"], r["c4"], r["cost"]))
            if r["template"] in ("story", "photo-caption"):
                first_pages.append(c.lastrowid)
        if covers:
            c.execute("UPDATE storybooks SET cover_img = %s WHERE book_id = %s", (covers[0], book_id))
        for u in other(author, rng.randint(6, 26)):
            c.execute("INSERT IGNORE INTO storybook_likes (book_id, eml, created_at) VALUES (%s,%s,%s)", (book_id, u, later(created, rng, 25)))
        for pid in first_pages:
            for _ in range(rng.choice([0, 1, 1, 2, 3])):
                c.execute("INSERT INTO storybook_comments (page_id, eml, body, created_at) VALUES (%s,%s,%s,%s)",
                          (pid, rng.choice(users), rng.choice(COMMENTS_GENERIC), later(created, rng, 25)))
        n_books += 1
    conn.commit()

    c.execute("SELECT (SELECT COUNT(*) FROM signup WHERE eml LIKE %s) users, (SELECT COUNT(*) FROM db WHERE eml LIKE %s) journals,"
              " (SELECT COUNT(*) FROM media WHERE eml LIKE %s) media, (SELECT COUNT(*) FROM media WHERE eml LIKE %s AND kind='video') videos,"
              " (SELECT COUNT(*) FROM media_likes WHERE eml LIKE %s) post_likes, (SELECT COUNT(*) FROM media_comments WHERE eml LIKE %s) post_comments,"
              " (SELECT COUNT(*) FROM journal_likes WHERE eml LIKE %s) journal_likes, (SELECT COUNT(*) FROM journal_comments WHERE eml LIKE %s) journal_comments,"
              " (SELECT COUNT(*) FROM storybooks WHERE eml LIKE %s) storybooks, (SELECT COUNT(*) FROM storybook_likes WHERE eml LIKE %s) book_likes",
              tuple("%" + DOMAIN for _ in range(10)))
    return c.fetchone()


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--remove", action="store_true", help="delete all demo content and its files")
    a = ap.parse_args()
    conn = get_connection()
    try:
        if a.remove:
            remove(conn)
            print("demo content removed")
        else:
            print("created:", create(conn))
    finally:
        conn.close()


if __name__ == "__main__":
    main()


