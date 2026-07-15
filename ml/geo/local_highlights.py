"""
JourneyAI — curated "local highlights" overrides for towns where OpenStreetMap
tagging misses or under-ranks genuinely famous local spots (e.g. Santram Mandir
in Nadiad has no wikidata/heritage tag, so it loses to a random 3-star hotel in
the OSM fame score). This module is a hand-verified supplement, NOT a
replacement — nearby_places() still does the live OSM/Geoapify fetch; this just
boosts or injects the entries below so real local fame wins.

Keyed by lowercased town name (matched against how the user typed the place,
e.g. "Nadiad", "nadiad, gujarat"). Each entry: name, category (matches the
categories used in ml/geo/places.py: religious/heritage/market/garden/food/
museum/attraction/other), a one-line note (shown as the "why" on the card),
and optionally lat/lon (if omitted, matched to the nearest same-named OSM POI
or placed at the town center as a fallback).

Food specialties are separate — a short list of real local dishes, each with
a "where" hint when a specific legendary vendor/street is commonly cited.
Surfaced as extra context (e.g. on itinerary/route "what to eat" sections),
not injected into the POI list itself (they're dishes, not places... unless
"where" names a specific stall, which fetch_pois can't discover on its own).
"""

# category values line up with places.py's classify() categories:
# religious | heritage | market(->shopping) | garden | food | museum | attraction | other

TOWNS = {
    "ahmedabad": {
        "character": "A UNESCO World Heritage city — Gujarat's historic textile and "
                      "trading hub, blending 600-year-old Sultanate-era mosques and "
                      "step-wells with a modern commercial economy.",
        "landmarks": [
            {"name": "Sabarmati Ashram", "category": "heritage",
             "note": "Gandhi's residence from 1917 and launch point of the 1930 Dandi Salt March."},
            {"name": "Sidi Saiyyed Mosque", "category": "heritage",
             "note": "1573 mosque famous for its carved stone 'tree of life' jali window."},
            {"name": "Bhadra Fort", "category": "heritage",
             "note": "Citadel built by founder Sultan Ahmed Shah in 1411."},
            {"name": "Manek Chowk", "category": "shopping",
             "note": "Morning vegetable market, daytime jewelry bazaar (India's 2nd-largest), "
                     "and a legendary street-food night market after 9:30pm."},
            {"name": "Kankaria Lake", "category": "garden",
             "note": "1451 circular lake with an island garden, zoo, and evening laser show."},
            {"name": "Law Garden", "category": "shopping",
             "note": "Evening street market ringing the garden, famous for chaniya choli and mirror-work."},
            {"name": "Sarkhej Roza", "category": "heritage",
             "note": "15th-century Sufi mosque-tomb complex, an Indo-Saracenic masterpiece."},
            {"name": "Hutheesing Jain Temple", "category": "religious",
             "note": "Elegant 1848 white-marble Jain temple noted for detailed stone carving."},
            {"name": "Calico Museum of Textiles", "category": "museum",
             "note": "World-renowned museum of historic Indian textiles, founded 1949."},
        ],
        "food": [
            {"dish": "Fafda-Jalebi", "where": "Chandravilas (120+ years) or Sunday-morning stalls citywide",
             "note": "Crispy gram-flour fafda with hot sugar-syrup jalebi, a weekend breakfast tradition."},
            {"dish": "Khaman", "where": "Das Khaman (since 1922) and street vendors citywide",
             "note": "Spongy steamed gram-flour snack topped with mustard tempering and sev."},
            {"dish": "Manek Chowk night-market food", "where": "Manek Chowk, after dark",
             "note": "Pav bhaji, dosas, bhajiyas, maska buns and kulfi till late night."},
            {"dish": "Bhatiyar Gali kebabs & haleem", "where": "Bhatiyar Gali, near Kalupur/Astodia",
             "note": "A ~600-year-old lane of Mughlai-style non-veg street food."},
            {"dish": "Gathiya", "where": "Gayatri Ganthiya, Iscon Gathiya, Sunday breakfast stalls",
             "note": "Deep-fried chickpea-flour snack, often eaten with fafda-jalebi."},
            {"dish": "Rajwadi Kulfi", "where": "Havmor (Ahmedabad-founded, 70+ years)",
             "note": "Rich cardamom-and-milk kulfi, often served as kulfi-falooda."},
        ],
    },

    "nadiad": {
        "character": "A temple town in Kheda district, nicknamed 'Sakshar Nagari' (city "
                      "of the literate) for its 19th-20th century Gujarati writers, and "
                      "best known locally for the Santram Mandir.",
        "landmarks": [
            {"name": "Santram Mandir", "category": "religious",
             "note": "Central shrine to Santram Maharaj, Nadiad's most-visited religious site "
                     "with daily aarti and full-moon celebrations."},
            {"name": "Mai Mandir", "category": "religious",
             "note": "Large Shiva temple (~135ft spire) near Nadiad Junction, among Gujarat's tallest."},
            {"name": "Shri Atmasiddhishastra Rachnabhoomi", "category": "heritage",
             "note": "Memorial where Jain poet-mystic Shrimad Rajchandra composed his philosophical text in 1895."},
            {"name": "Dahilaxmi Library", "category": "heritage",
             "note": "Historic library founded by writer Mansukhram Tripathi — emblematic of the 'Sakshar' reputation."},
        ],
        "food": [
            {"dish": "Nadiadi Bhusu", "where": "Chandra Snacks (since 1946) and other legacy namkeen makers",
             "note": "Nadiad's signature ten-ingredient fried snack mix (sev, gathiya, chevdo, fulwadi, bundi...) — "
                     "sold across Gujarat but originates here."},
            {"dish": "Gathiya & sev farsan", "where": "Farsan shops on/around Santram Road",
             "note": "Deep-fried gram-flour strands, a core ingredient of Nadiadi Bhusu, also sold standalone."},
        ],
    },

    "anand": {
        "character": "India's dairy-cooperative capital — 'Milk Capital of India', home to "
                      "Amul and the NDDB, birthplace of the White Revolution.",
        "landmarks": [
            {"name": "Amul Dairy Plant", "category": "attraction",
             "note": "Bookable guided tour of Asia's largest dairy cooperative plant, with a "
                     "museum on the milk revolution (book via visit.amul.in)."},
            {"name": "Dr. Verghese Kurien Memorial Museum", "category": "museum",
             "note": "Honors the 'Father of the White Revolution' with his awards and interactive exhibits."},
            {"name": "BAPS Shri Swaminarayan Mandir", "category": "religious",
             "note": "Six-storeyed marble-and-sandstone lotus-shaped temple with nine inner domes."},
            {"name": "ISKCON Vallabh Vidyanagar", "category": "religious",
             "note": "Hare Krishna temple with daily evening aarati and prasad."},
        ],
        "food": [
            {"dish": "Amul dairy products", "where": "Amul Parlour outlets across Anand, near the plant",
             "note": "Anand is the literal home of Amul — fresh butter, cheese, and ice cream, "
                     "and the parlours are themselves a visited spot."},
            {"dish": "Darbar Mug Pulao", "where": "Darbar Mug Pulao & Fast Food, Vallabh Vidyanagar",
             "note": "A decades-old Anand institution — spiced sprouted-moong pulao with curd and chutney."},
            {"dish": "Kachori", "where": "Street vendors across Anand",
             "note": "Deep-fried pastry filled with spiced lentil/pea mixture, a staple Anand street food."},
        ],
    },

    "vadodara": {
        "character": "Royal heritage city (Baroda) — former capital of the princely "
                      "Gaekwad dynasty, known for grand Indo-Saracenic architecture and gardens.",
        "landmarks": [
            {"name": "Laxmi Vilas Palace", "category": "heritage",
             "note": "1890 Indo-Saracenic palace, roughly 4x the size of Buckingham Palace, "
                     "still privately owned by the Gaekwad family."},
            {"name": "Sayaji Baug", "category": "garden",
             "note": "Sprawling garden with a zoo, planetarium, health museum, and toy train."},
            {"name": "Baroda Museum & Picture Gallery", "category": "museum",
             "note": "Founded 1887 inside Sayaji Baug, modeled on London's V&A Museum."},
            {"name": "EME Temple", "category": "religious",
             "note": "1966 geodesic-dome temple blending symbols of five major religions."},
            {"name": "Sursagar Lake", "category": "attraction",
             "note": "18th-century city-center lake with a 111ft gold-gilded Shiva statue."},
            {"name": "Kirti Mandir", "category": "heritage",
             "note": "1936 Gaekwad family cenotaph with wall paintings by Nandalal Bose."},
        ],
        "food": [
            {"dish": "Sev Usal", "where": "Mahakali Sev Usal, near Kirti Stambh/Polo Ground (since 1988)",
             "note": "Vadodara's signature dish — a white-pea curry topped with crispy sev and onions."},
            {"dish": "Sev Khamani", "where": "Street stalls in the Mandvi/Raopura area",
             "note": "Crumbled, tangy-sweet khaman garnished with sev, onion, and pomegranate."},
            {"dish": "Jalebi & Malpua", "where": "Mandvi street-food area",
             "note": "Classic sweets sold in the crowded lanes of one of Vadodara's oldest food hubs."},
        ],
    },

    "surat": {
        "character": "India's diamond-cutting and textile capital — a fast-growing "
                      "industrial hub on the Tapi River, polishing ~80-90% of the world's diamonds.",
        "landmarks": [
            {"name": "Surat Castle", "category": "heritage",
             "note": "16th-century riverside fortress built by Sultan Mahmud III."},
            {"name": "Chintamani Jain Temple", "category": "religious",
             "note": "400-year-old Jain temple near Rani Talab, famed for wood carvings ('Wooden Temple')."},
            {"name": "Dutch Garden", "category": "garden",
             "note": "European-style landscaped garden in Nanpura with fountains and lawns."},
            {"name": "Surat Diamond Bourse", "category": "attraction",
             "note": "Opened 2023 — the world's largest office building (7.1M sq ft), built for the diamond trade."},
            {"name": "Surat Textile Market, Ring Road", "category": "shopping",
             "note": "One of India's busiest wholesale saree and fabric trading markets."},
            {"name": "Dumas Beach", "category": "other",
             "note": "Arabian Sea beach ~21km out, known for unusual black iron-rich sand."},
            {"name": "Sarthana Nature Park", "category": "garden",
             "note": "81-acre park, Gujarat's largest zoo, home to lions, tigers and leopards."},
        ],
        "food": [
            {"dish": "Locho", "where": "Sagrampura / Chowk Bazar (e.g. JP Khaman, Gopal Locho)",
             "note": "Soft, loose gram-flour and yogurt steamed snack — a Surat-invented street food."},
            {"dish": "Surti Undhiyu", "where": "Widely sold across Surat, especially winter street stalls",
             "note": "Mixed-vegetable dish slow-cooked upside-down in earthen pots — a signature winter dish."},
            {"dish": "Ghari", "where": "Shah Jamnadas C. Ghariwala (est. 1899) and old-city sweet shops",
             "note": "Round sweet of mawa, ghee and sugar in a puri shell, tied to the Chandani Padvo festival."},
            {"dish": "Ponk", "where": "Seasonal street vendors citywide, Dec-Jan only",
             "note": "Green sorghum seeds harvested only in the coldest winter weeks."},
        ],
    },

    "rajkot": {
        "character": "Saurashtra's largest commercial hub — royal-era architecture, deep "
                      "Gandhi heritage (his childhood home and school are here), and lively "
                      "evening street food.",
        "landmarks": [
            {"name": "Watson Museum", "category": "museum",
             "note": "One of Gujarat's oldest museums (1888) with Kathiawar royal and Indus Valley relics."},
            {"name": "Kaba Gandhi No Delo", "category": "heritage",
             "note": "Gandhi's ancestral family home (1881-1887), now a pictorial museum of his life."},
            {"name": "Mahatma Gandhi Museum", "category": "museum",
             "note": "Housed in the former Alfred High School where Gandhi studied — 39 galleries."},
            {"name": "Jubilee Garden", "category": "garden",
             "note": "Central park in Lohana Para with a royal cenotaph, home to the Watson Museum."},
            {"name": "Rotary Dolls Museum", "category": "museum",
             "note": "Over 1,600 dolls from 100+ countries — listed in the Limca Book of Records."},
            {"name": "Jagat Mandir", "category": "religious",
             "note": "168-foot marble temple (1934) modeled on Belur Math, within Ramakrishna Ashram."},
        ],
        "food": [
            {"dish": "Rajkot Penda", "where": "Jay Siyaram Pendawala, Panchnath Main Road",
             "note": "Rajkot's signature milk sweet, distinct for being made from plain milk."},
            {"dish": "Gujarati farsan (dhokla, khaman, khandvi)", "where": "Sadar Bazar street stalls",
             "note": "Classic steamed/fried Gujarati snacks widely sold across the city's markets."},
            {"dish": "Chaat & evening snacks", "where": "C.S. Soni Chowk",
             "note": "A well-known local hangout chowk for Gujarati snacks, busiest in the evenings."},
        ],
    },

    "gandhinagar": {
        "character": "The planned, green administrative capital of Gujarat, built in the "
                      "1960s along the Sabarmati River, known for wide tree-lined sectors "
                      "and government institutions.",
        "landmarks": [
            {"name": "Swaminarayan Akshardham", "category": "religious",
             "note": "23-acre BAPS temple complex in Sector 20, built from pink sandstone with "
                     "no steel/iron, plus exhibition halls and an IMAX theater. (A separate, "
                     "smaller Akshardham exists in Ahmedabad — do not conflate the two.)"},
            {"name": "Adalaj Stepwell", "category": "heritage",
             "note": "Five-story 15th-century Solanki-style stepwell in Adalaj village, ~5km from the city."},
            {"name": "Indroda Nature Park", "category": "museum",
             "note": "400-hectare park with India's only dinosaur fossil park and museum, plus a zoo."},
            {"name": "Sarita Udyan", "category": "garden",
             "note": "Gandhinagar's oldest and largest garden (30 acres) with a deer park, boating, toy train."},
            {"name": "Mahatma Mandir", "category": "attraction",
             "note": "34-acre convention centre inspired by Gandhi, host of Vibrant Gujarat summits."},
            {"name": "Dandi Kutir Museum", "category": "museum",
             "note": "Experiential Gandhi museum shaped as a 41-meter salt-mound dome with holography exhibits."},
        ],
        "food": [
            {"dish": "Dhokla", "where": "Sector 21 street-food area (e.g. Pooja Parlour & Fast Food Centre)",
             "note": "Steamed, fermented gram-flour cake — cited as Gandhinagar's most-ordered street item."},
            {"dish": "Fafda-Jalebi", "where": "Sector 21 market",
             "note": "Classic Gujarati combo of crispy fafda strips with sweet, syrup-soaked jalebi."},
            {"dish": "Swaminarayan Khichdi", "where": "Premvati Food Court, inside the Akshardham complex",
             "note": "Self-service canteen serving satvik (no onion/garlic) fare; the khichdi is a signature item."},
        ],
    },

    "jamnagar": {
        "character": "A coastal former princely-state capital on the Gulf of Kutch, known as "
                      "'Chhoti Kashi' for its dense cluster of temples, and as India's 'Brass City'.",
        "landmarks": [
            {"name": "Lakhota Fort", "category": "heritage",
             "note": "19th-century fort on an island in Lakhota Lake, now a museum of regional artifacts."},
            {"name": "Ranmal Lake", "category": "garden",
             "note": "Large artificial lake in the city center, popular for walks and boating."},
            {"name": "Bala Hanuman Temple", "category": "religious",
             "note": "Guinness World Record holder for unbroken 24-hour Ram-dhun chanting since 1964."},
            {"name": "Khambhalia Gate", "category": "heritage",
             "note": "17th-century surviving city gate, restored as a heritage gallery in 2016."},
            {"name": "Pratap Vilas Palace", "category": "heritage",
             "note": "Indo-Saracenic royal palace (1907-1915) notable for its three glass domes."},
            {"name": "Willingdon Crescent", "category": "shopping",
             "note": "European-style arcaded market crescent, today a shopping hub famed for bandhani textiles."},
        ],
        "food": [
            {"dish": "Jamnagari Ghughra", "where": "Street vendors and farsan shops citywide (evening)",
             "note": "Deep-fried, pleated dumpling stuffed with spiced potato, white peas, ginger and garlic."},
            {"dish": "Jamnagari Kachori", "where": "Kachori Junction / Shreeji Pavan Kachori (some since 1979)",
             "note": "Crisp, layered deep-fried kachori with moong dal stuffing, sold with aloo sabzi."},
            {"dish": "Farali/Lilo Chevdo", "where": "Local farsan shops (e.g. Halar Food Products)",
             "note": "Savory fried gram-flour/sev-based snack mix, a long-standing local farsan specialty."},
        ],
    },

    "junagadh": {
        "character": "A historic Nawabi-era city at the foot of the sacred Girnar hill, "
                      "and the traditional gateway to Gir Forest, home of the Asiatic lion.",
        "landmarks": [
            {"name": "Uparkot Fort", "category": "heritage",
             "note": "Ancient hilltop fort (roots to 319 BCE) with Buddhist caves and the Adi-Kadi Vav stepwell."},
            {"name": "Girnar Hill", "category": "religious",
             "note": "Sacred Jain/Hindu pilgrimage mountain with ~9,999-10,000 steps past Ambaji Temple "
                     "and Gorakhnath Peak, Gujarat's highest point."},
            {"name": "Mahabat Maqbara", "category": "heritage",
             "note": "Ornate 1892 royal mausoleum blending Indo-Islamic and Gothic styles, with silver doors."},
            {"name": "Sakkarbaug Zoological Garden", "category": "attraction",
             "note": "India's oldest zoo (1863), a major Asiatic lion breeding center."},
            {"name": "Darbar Hall Museum", "category": "museum",
             "note": "Former royal court of the Babi Nawab dynasty, displaying thrones, weapons and costumes."},
            {"name": "Willingdon Dam", "category": "garden",
             "note": "British-era reservoir at the base of the Girnar hills, a popular picnic/birdwatching spot."},
        ],
        "food": [
            {"dish": "Sev Khamani", "where": "Street stalls and local eateries citywide",
             "note": "Crumbled khaman dhokla tossed with sev, chana mixture and pomegranate."},
            {"dish": "Lilva Kachori", "where": "Local sweet/snack shops (winter season)",
             "note": "Kachori stuffed with fresh green pigeon-peas — a seasonal Saurashtra favorite."},
            {"dish": "Bhajiya", "where": "Rokadiya, Junagadh",
             "note": "Deep-fried gram-flour fritters; Rokadiya is a well-known local bhajiya spot."},
            {"dish": "Basundi", "where": "Local sweet shops",
             "note": "Thickened, sweetened milk dessert flavored with cardamom and nuts."},
        ],
    },

    "bhavnagar": {
        "character": "A former princely-state capital (Gohil Rajput dynasty) on the Gulf "
                      "of Khambhat, with royal heritage and a signature street-food invention.",
        "landmarks": [
            {"name": "Takhteshwar Temple", "category": "religious",
             "note": "Hilltop all-white-marble Shiva temple (1893) with panoramic views to the Gulf."},
            {"name": "Nilambag Palace", "category": "heritage",
             "note": "Former royal residence (1879) in Anglo-Indian style, now a heritage hotel."},
            {"name": "Darbargadh", "category": "heritage",
             "note": "Early-1800s royal palace-gate complex of the Bhavnagar princely state."},
            {"name": "Gangajaliya Talav", "category": "garden",
             "note": "Historic lake with a white-marble Jain temple pavilion (Ganga Deri) on the water."},
            {"name": "Victoria Park", "category": "garden",
             "note": "Sprawling ~500-acre historic urban forest established in 1888."},
            {"name": "Barton Library & Museum", "category": "museum",
             "note": "One of Kathiawad's oldest libraries (1882), with an attached Gandhi Smriti museum."},
        ],
        "food": [
            {"dish": "Pav Gathiya", "where": "Lachhubhai Ganthiyawala, Ghogha Circle (since 1951)",
             "note": "Bhavnagar's own street-food invention — crispy gathiya with soft pav, onion and chutney."},
            {"dish": "Bhavnagari Gathiya", "where": "Lachhubhai, Manubhai Gathiyawala, Jay Gopal Farsan House",
             "note": "Crunchy fried gram-flour snack that gave this Gujarati farsan style its national name."},
            {"dish": "Fafda-Jalebi", "where": "Citywide breakfast stalls",
             "note": "Classic Gujarati breakfast pairing, called an iconic Bhavnagar morning staple."},
        ],
    },

    "bharuch": {
        "character": "One of India's oldest continuously inhabited cities — a historic "
                      "Narmada-river port (ancient 'Barygaza') of temples, gates, and bazaars.",
        "landmarks": [
            {"name": "Bhrigu Rishi Temple", "category": "religious",
             "note": "Riverside 17th-century Maratha-built temple honoring the sage the city is named for."},
            {"name": "Jama Masjid", "category": "heritage",
             "note": "14th-century mosque built partly from the remains of an earlier Jain temple."},
            {"name": "Bharuch Fort", "category": "heritage",
             "note": "~1,000-year-old hilltop fort overlooking the Narmada, with colonial-era additions."},
            {"name": "Kabirvad", "category": "garden",
             "note": "Narmada island dominated by a sprawling 2.5+ acre banyan tree linked to saint Kabir."},
            {"name": "Golden Bridge", "category": "heritage",
             "note": "British-era iron bridge (1877-1881) over the Narmada linking Bharuch and Ankleshwar."},
        ],
        "food": [
            {"dish": "Khari Sing (roasted salted peanuts)", "where": "Bharuch region",
             "note": "Locally roasted peanuts, widely marketed as a genuine Bharuch specialty."},
            {"dish": "Bharuchi seafood", "where": "Home-style Bharuchi cooking",
             "note": "Pomfret, Bombay duck, prawns with rotli/rotla — reflecting the town's river-port "
                     "Surti-Kathiyawadi-Parsi culinary crossroads identity."},
        ],
    },

    "porbandar": {
        "character": "A coastal Kathiyawadi port town, best known worldwide as the birthplace "
                      "of Mahatma Gandhi, with a working fishing harbor and maritime history.",
        "landmarks": [
            {"name": "Kirti Mandir", "category": "heritage",
             "note": "Museum and memorial at Gandhi's ancestral birth house, inaugurated 1950."},
            {"name": "Sudama Mandir", "category": "religious",
             "note": "White-marble temple (1902-1907) dedicated to Sudama, Krishna's childhood friend."},
            {"name": "Chowpatty Beach", "category": "beach",
             "note": "Clean, tranquil Arabian Sea beach near the city center."},
            {"name": "Porbandar Bird Sanctuary", "category": "attraction",
             "note": "Wetland sanctuary near Chowpatty with ~150-200 resident/migratory bird species."},
            {"name": "Sartanji Choro", "category": "heritage",
             "note": "Three-storey fort/palace at the city's highest point, built by Rana Sartanji."},
            {"name": "Nehru Planetarium", "category": "museum",
             "note": "Astronomy museum and planetarium inaugurated 1972 by Indira Gandhi."},
        ],
        "food": [
            {"dish": "Khajali", "where": "Citywide, dozens of dedicated snack-maker shops",
             "note": "Porbandar's signature snack — flaky ghee-fried wheat-flour biscuits, sweet or masala; "
                     "production is concentrated almost exclusively in Porbandar."},
            {"dish": "Pomfret & prawns (seafood)", "where": "Local seafood restaurants and the fishing harbor market",
             "note": "Porbandar is one of Gujarat's leading seafood hubs, grilled or in spicy masala."},
        ],
    },

    "dwarka": {
        "character": "One of Hinduism's holiest pilgrimage towns — part of the Char Dham "
                      "circuit and traditionally Krishna's ancient coastal kingdom.",
        "landmarks": [
            {"name": "Dwarkadhish Temple", "category": "religious",
             "note": "Main shrine to Krishna as 'Lord of Dwarka', a five-storied temple on 72 pillars."},
            {"name": "Nageshvara Jyotirlinga Temple", "category": "religious",
             "note": "One of the 12 sacred Jyotirlingas of Shiva, ~18km from Dwarka, with an 80ft Shiva statue."},
            {"name": "Rukmini Devi Temple", "category": "religious",
             "note": "Richly carved 12th-19th century temple to Krishna's queen Rukmini."},
            {"name": "Bet Dwarka", "category": "heritage",
             "note": "Island ~30km out, reached by boat, traditionally held as Krishna's residence."},
            {"name": "Gomti Ghat", "category": "heritage",
             "note": "Riverside ghat where the Gomti River meets the sea, lined with shrines."},
            {"name": "Dwarka Beach", "category": "beach",
             "note": "Arabian Sea beach near the main temple, known for calm sunrises and marine life."},
        ],
        "food": [
            {"dish": "Sattvik Temple Thali / Bhog", "where": "Temple trust dining halls near Dwarkadhish Temple",
             "note": "Pure vegetarian meals without onion/garlic, reflecting the town's pilgrimage food culture."},
            {"dish": "Panchamrit and Peda", "where": "Dwarkadhish Temple prasad counters",
             "note": "The prasad distributed to devotees as part of the daily bhog."},
        ],
    },

    "somnath": {
        "character": "A major Hindu pilgrimage town on the Saurashtra coast, built around "
                      "the Somnath Jyotirlinga temple, with Veraval's fishing port nearby.",
        "landmarks": [
            {"name": "Somnath Temple", "category": "religious",
             "note": "The first of the 12 Jyotirlingas of Shiva, rebuilt 1950, set on the Arabian Sea shore."},
            {"name": "Triveni Sangam", "category": "heritage",
             "note": "Sacred confluence of three rivers meeting the sea beside the temple."},
            {"name": "Bhalka Tirth", "category": "heritage",
             "note": "Traditionally identified as where Krishna was struck by a hunter's arrow."},
            {"name": "Prabhas Patan Museum", "category": "museum",
             "note": "Archaeological museum displaying sculptures recovered from earlier temple versions."},
            {"name": "Somnath Beach", "category": "beach",
             "note": "Shoreline beside the temple, site of an evening sound-and-light show."},
        ],
        "food": [
            {"dish": "Temple prasad (gathiya, chikki, laddoo)", "where": "Somnath Temple premises",
             "note": "Traditional satvik snacks distributed to pilgrims."},
            {"dish": "Seafood", "where": "Veraval fishing harbour, ~5km away",
             "note": "One of India's major fishing ports, distinct from the vegetarian pilgrim food at the temple."},
        ],
    },

    "palanpur": {
        "character": "A North Gujarat town historically ruled by Muslim Nawabs, today "
                      "globally known as the ancestral home of the Palanpuri diamond-trade Jain community.",
        "landmarks": [
            {"name": "Balaram Palace", "category": "heritage",
             "note": "Neo-classical hunting palace (1922-1936) on the Balaram River, now a heritage hotel."},
            {"name": "Kirti Stambh", "category": "heritage",
             "note": "Commemorative 1918 tower engraved with the lineage of Palanpur's rulers."},
            {"name": "Pallaviya Parshwanath Temple", "category": "religious",
             "note": "Historic Jain temple whose idol reportedly survived the 2001 earthquake undamaged."},
        ],
        "food": [
            {"dish": "Kaju Katli & Mohanthal", "where": "Alpahar Sweets, Palanpur",
             "note": "Handcrafted ghee-based sweets from a well-known local confectionery."},
        ],
    },

    "patan": {
        "character": "A UNESCO-recognized historic capital of the Chaulukya (Solanki) "
                      "dynasty, renowned for stepwell architecture and Patola weaving.",
        "landmarks": [
            {"name": "Rani ki Vav", "category": "heritage",
             "note": "11th-century subterranean stepwell, UNESCO World Heritage Site, depicted on the ₹100 note."},
            {"name": "Sahastralinga Talav", "category": "heritage",
             "note": "11th-century artificial lake ('lake of a thousand lingams') on the Saraswati River."},
            {"name": "Panchasara Parshwanath Temple", "category": "religious",
             "note": "8th-century Jain temple housing the historic Hemchandracharya manuscript library."},
            {"name": "Patan Patola Heritage Museum", "category": "museum",
             "note": "Documents the 900-year-old Patola double-ikat weaving tradition, with live demonstrations."},
        ],
        "food": [
            {"dish": "Devada (Sata)", "where": "Bhagwati Sweet Mart and other local sweet shops",
             "note": "Crispy, flaky, layered sweet — Patan's signature traditional sweet for festivals/weddings."},
            {"dish": "Patola sarees (craft, not food)", "where": "Salvi family workshops, Patan",
             "note": "Hand-woven double-ikat silk sarees, a centuries-old technique preserved by the Salvi family."},
        ],
    },

    "mehsana": {
        "character": "A North Gujarat town best known as a major dairy-cooperative hub "
                      "(home to Dudhsagar Dairy) with historically significant nearby temples.",
        "landmarks": [
            {"name": "Simandhar Swami Jain Temple", "category": "religious",
             "note": "Prominent Jain pilgrimage temple in Mehsana town, built 1972, with a 146-inch idol."},
            {"name": "Dudhsagar Dairy", "category": "attraction",
             "note": "India's largest cooperative dairy, headquartered in Mehsana — the town's Amul-like anchor."},
            {"name": "Modhera Sun Temple", "category": "heritage",
             "note": "11th-century Sun Temple ~26-28km away in Mehsana district (see Modhera entry)."},
            {"name": "Bahuchar Mata Temple, Becharaji", "category": "religious",
             "note": "Major Shakti Peeth ~35km away, a key pilgrimage site."},
        ],
        "food": [
            {"dish": "Doodhpak", "where": "Associated with Mehsana's dairy abundance",
             "note": "Rich, slow-cooked full-fat milk rice pudding with cardamom, saffron and nuts."},
            {"dish": "Shrikhand", "where": "Produced by Dudhsagar Dairy",
             "note": "Strained-yogurt sweet flavored with saffron and cardamom, an actual dairy product line."},
        ],
    },

    "bhuj": {
        "character": "The historic, earthquake-rebuilt royal capital of Kutch, and the "
                      "main gateway to the White Rann of Kutch and its craft villages.",
        "landmarks": [
            {"name": "Aina Mahal", "category": "heritage",
             "note": "18th-century royal residence famed for ornate mirror-glasswork walls and ceilings."},
            {"name": "Prag Mahal", "category": "heritage",
             "note": "19th-century Indo-European palace blending Italian Gothic and Rajput styles."},
            {"name": "Great Rann of Kutch (White Desert)", "category": "attraction",
             "note": "One of the world's largest salt deserts; Bhuj is the gateway, Dhordo ~80-90km away."},
            {"name": "Bhujodi Village", "category": "shopping",
             "note": "Weaving village ~8km out, home to the distinctive Bhujodi handloom textile style."},
            {"name": "Nirona Village", "category": "shopping",
             "note": "Craft village known for rare Rogan art (heated castor-oil paste painting)."},
            {"name": "Bhujia Fort", "category": "heritage",
             "note": "Hilltop fort (1715-1718) overlooking Bhuj city, popular for sunrise/sunset views."},
        ],
        "food": [
            {"dish": "Kutchi Dabeli", "where": "Originated in Mandvi, Kutch (1960s, Keshavji Gabha Chudasama)",
             "note": "Spiced mashed-potato in a pav bun with chutneys, peanuts and sev — Kutch's signature street food."},
            {"dish": "Bajra Rotla with lasan chutney", "where": "Rural Kutch generally",
             "note": "Thick millet flatbread served hot with white butter, jaggery, and fiery garlic chutney."},
            {"dish": "Adadiya", "where": "Kutch, winter-only",
             "note": "Urad-dal-and-ghee-based sweet traditionally made and eaten only in winter."},
        ],
    },

    "valsad": {
        "character": "A coastal district headquarters town in south Gujarat on the "
                      "Arabian Sea, known for its black-sand beach and Parsi food influence.",
        "landmarks": [
            {"name": "Tithal Beach", "category": "beach",
             "note": "~6km black-sand beach lined with palm trees, popular for water sports."},
            {"name": "Parnera Hill (Parnera Fort)", "category": "heritage",
             "note": "Hilltop fort linked by legend to Shivaji, with several temples and a trekking spot."},
            {"name": "Bilpudi Waterfalls", "category": "nature",
             "note": "Waterfall ~10km from Dharampur, a nature excursion near Valsad."},
        ],
        "food": [
            {"dish": "Parsi dishes", "where": "Parsi restaurants in town",
             "note": "Salli per eedu, kheema pav, mutton pulao dar — reflecting the region's Parsi community."},
            {"dish": "Tithal beach street food", "where": "Tithal beach stalls",
             "note": "Bhel puri, pani puri, dabeli, bhajiya, roasted corn — a lively beachfront food hub."},
        ],
    },

    "navsari": {
        "character": "A historic south Gujarat town regarded as one of India's most "
                      "significant Parsi settlements, and the birthplace of Jamsetji Tata.",
        "landmarks": [
            {"name": "J.N. Tata Birthplace", "category": "heritage",
             "note": "Restored house in Dasturwad where Jamsetji Tata was born in 1839, now a free museum."},
            {"name": "Vadi Dar-e-Meher", "category": "religious",
             "note": "~850-year-old Zoroastrian fire temple complex where Jamsetji Tata was ordained a priest."},
            {"name": "Bhagarsath Anjuman Atash Behram", "category": "religious",
             "note": "One of India's oldest Atash Behram fire temples, consecrated in 1765."},
            {"name": "Meherjirana Library", "category": "museum",
             "note": "Founded 1874, one of the world's richest collections of books on Zoroastrianism."},
            {"name": "Dandi Beach", "category": "beach",
             "note": "Coastal village where Gandhi's 1930 Salt March ended, marked by a 2019 memorial."},
        ],
        "food": [
            {"dish": "Dhansak", "where": "Parsi homes/restaurants",
             "note": "The signature Parsi dish of lentils and meat cooked together with caramelized rice."},
            {"dish": "Brun pav", "where": "Navsari's century-old Parsi bakeries",
             "note": "Crusty-outside, soft-inside bread, a Parsi breakfast staple."},
            {"dish": "Kolah pickles and cane vinegar", "where": "E.F. Kolah & Sons, Bamji Street (since 1885)",
             "note": "A Parsi condiment maker famous for cane vinegar and pickles like gharab nu achar."},
        ],
    },

    "diu": {
        "character": "A small former Portuguese colonial enclave off Gujarat's coast — "
                      "whitewashed churches, a seafront fort, and seafood-forward cuisine.",
        "landmarks": [
            {"name": "Diu Fort", "category": "heritage",
             "note": "Massive Portuguese fortress built 1535, perched on the coast with sweeping sea views."},
            {"name": "St. Paul's Church", "category": "religious",
             "note": "c.1601-1610 Baroque church, considered one of the finest examples in India."},
            {"name": "Nagoa Beach", "category": "beach",
             "note": "Diu's most popular beach, horseshoe-shaped with calm, palm-fringed waters."},
            {"name": "Gangeshwar Mahadev Temple", "category": "religious",
             "note": "Seaside cave temple with five Shiva lingas naturally washed by tidal sea waves."},
            {"name": "Zampa Gateway", "category": "heritage",
             "note": "Carved Portuguese-era gateway marking the entrance to the old fort area."},
        ],
        "food": [
            {"dish": "Crab Masala", "where": "Seafood restaurants in Diu",
             "note": "Fresh crab in a spicy masala, cited as a signature Diu delicacy."},
            {"dish": "Kalwa (Clam) Curry", "where": "Seafood restaurants in Diu",
             "note": "A distinctive Diu coastal specialty, not typical of vegetarian mainland Gujarat."},
            {"dish": "Fudina Machhi", "where": "Seafood restaurants in Diu",
             "note": "Fish marinated in mint and spices, grilled or fried."},
        ],
    },

    "saputara": {
        "character": "Gujarat's only hill station, in the Sahyadri range within the "
                      "tribal Dangs district — cool climate, a lake, and Bhil/Warli tribal culture.",
        "landmarks": [
            {"name": "Saputara Lake", "category": "nature",
             "note": "Central artificial lake with paddle-boat and rowboat rides, the town's main gathering spot."},
            {"name": "Sunrise Point", "category": "nature",
             "note": "Viewpoint reached by hike or ropeway, panoramic views over the Western Ghats at dawn."},
            {"name": "Sunset Point (Gandhi Shikhar)", "category": "nature",
             "note": "Popular vantage point overlooking the Dang forest, especially busy at sunset."},
            {"name": "Saputara Tribal Museum", "category": "museum",
             "note": "Displays art, weapons, jewelry and everyday objects of the local Bhil and Warli tribes."},
            {"name": "Artist Village", "category": "shopping",
             "note": "Craft center best known for traditional and tribal artifacts, including Warli paintings."},
        ],
        "food": [
            {"dish": "Lemongrass chai", "where": "Town/lakeside stalls",
             "note": "A locally grown-lemongrass-infused tea, a Saputara specialty."},
            {"dish": "Spicy boiled corn (bhutta)", "where": "Roadside/lake-area stalls",
             "note": "Hot, spiced boiled corn, especially popular in the cooler hill-station weather."},
        ],
    },

    "ambaji": {
        "character": "A major Hindu pilgrimage town on the Gujarat-Rajasthan border, "
                      "centered on one of the 51 Shakti Peeths.",
        "landmarks": [
            {"name": "Ambaji Temple", "category": "religious",
             "note": "White marble Shakti Peeth temple; the goddess is represented by a veiled yantra, not an idol."},
            {"name": "Gabbar Hill", "category": "religious",
             "note": "Sacred hilltop ~1.5km above town via 999 steps or ropeway, the original seat of the goddess."},
            {"name": "Mansarovar Kund", "category": "heritage",
             "note": "Large stepped water tank near the main temple, a traditional pilgrim bathing site."},
            {"name": "Shaktipeeth Parikrama", "category": "heritage",
             "note": "~2.5-3km walking route lined with 51 symbolic replica temples of all the Shakti Peeths."},
        ],
        "food": [
            {"dish": "Mohanthal prasad", "where": "Distributed by the Shree Arasuri Ambaji Mata Devasthan Trust",
             "note": "The temple's official besan-ghee-sugar sweet prasad — ~1.25 crore units sold annually."},
        ],
    },

    "modhera": {
        "character": "A small village whose identity is built almost entirely around a "
                      "single 11th-century architectural masterpiece, the Sun Temple.",
        "landmarks": [
            {"name": "Modhera Sun Temple", "category": "heritage",
             "note": "11th-century (1026-27 CE) temple dedicated to Surya, built by King Bhima I of the Solanki dynasty."},
            {"name": "Surya Kund", "category": "heritage",
             "note": "Rectangular stepped tank in front of the temple with 108 miniature shrines."},
            {"name": "Sabha Mandap", "category": "heritage",
             "note": "Intricately carved pillared assembly hall between the Surya Kund and the main shrine."},
        ],
        "food": [
            {"dish": "Fafda-Jalebi", "where": "General Gujarati region, not Modhera-specific",
             "note": "Classic breakfast pairing — Modhera itself has no distinct signature dish beyond regional fare."},
        ],
    },

    "palitana": {
        "character": "A pilgrimage town at the foot of Shatrunjaya Hill, famous worldwide "
                      "as a 'city of temples' and widely reported as India's first vegetarian-only city.",
        "landmarks": [
            {"name": "Shatrunjaya Hill temple complex", "category": "religious",
             "note": "A dense complex of roughly 900+ Jain shrines built up over ~900 years, on two hill ridges."},
            {"name": "Adinath Temple", "category": "religious",
             "note": "The largest and most splendid temple in the complex, dedicated to the first Jain Tirthankara."},
            {"name": "Chaumukhji Tunk", "category": "religious",
             "note": "Built 1618, features four images of Adinath facing the four cardinal directions."},
        ],
        "food": [
            {"dish": "Dal Dhokli", "where": "General Gujarati/Jain-region dish, eaten locally",
             "note": "Wheat-flour dumplings simmered in lentil curry — Palitana's food culture is Jain-vegetarian, "
                     "with thin distinct local-specialty data beyond regional fare."},
        ],
    },
}


def _normalize(place):
    """Extract a bare town key from a free-text place string like 'Nadiad, Gujarat, India'."""
    if not place:
        return ""
    first = place.split(",")[0].strip().lower()
    return first


def get_town(place):
    """Return the curated TOWNS entry for a place string, or None if not covered."""
    return TOWNS.get(_normalize(place))


def boost_and_inject(pois, place, town_lat=None, town_lon=None):
    """
    Given the OSM/Geoapify POI list for a place, boost fame for any curated
    landmark that's already present (fuzzy name match), and inject curated
    landmarks that are missing entirely (using the town center as a fallback
    location, since we don't have exact lat/lon for the injected entry).

    Mutates and returns `pois`. Injected entries carry curated=True and a
    "note" field so the UI can show *why* it's featured even without wikidata.
    """
    town = get_town(place)
    if not town:
        return pois

    by_lower_name = {}
    for p in pois:
        by_lower_name.setdefault(p["name"].strip().lower(), []).append(p)

    FAME_BOOST = 6.0  # comfortably beats any organic OSM fame (max ~6.5) so curated spots rank first

    matched_names = set()
    for lm in town["landmarks"]:
        key = lm["name"].strip().lower()
        hit = None
        # exact match, then "contains" match (handles OSM's fuller official names,
        # e.g. curated "Girnar Hill" vs OSM "Girnar Hill Ropeway"). Guard against
        # short generic OSM names (e.g. bare "Temple") false-matching a longer
        # curated name by requiring the shorter side to be a substantial fraction
        # of the longer side, not just any tiny substring.
        if key in by_lower_name:
            hit = by_lower_name[key][0]
        else:
            for name_l, plist in by_lower_name.items():
                shorter, longer = (name_l, key) if len(name_l) <= len(key) else (key, name_l)
                if shorter in longer and len(shorter) >= 0.6 * len(longer) and len(shorter) >= 6:
                    hit = plist[0]
                    break
        if hit:
            hit["fame"] = round(hit.get("fame", 0.0) + FAME_BOOST, 1)
            hit["curated"] = True
            hit["note"] = lm["note"]
            matched_names.add(key)
        else:
            # OSM missed it entirely — inject using the town center as location
            # (approximate; still lets it appear in the list and on the map).
            if town_lat is not None and town_lon is not None:
                pois.append({
                    # dist_km must clear nearby_places()'s "> 0.6 skip origin" filter
                    "name": lm["name"], "lat": town_lat, "lon": town_lon,
                    "category": lm["category"], "dist_km": 1.0,
                    "fame": FAME_BOOST, "image": "", "wikidata": "", "wikipedia": "",
                    "cuisine": "", "curated": True, "note": lm["note"],
                })
    return pois


def food_specialties(place):
    """Return the curated local food list for a place, or [] if not covered."""
    town = get_town(place)
    return town["food"] if town else []


def town_character(place):
    """One-line character blurb for a place, or '' if not covered."""
    town = get_town(place)
    return town["character"] if town else ""
