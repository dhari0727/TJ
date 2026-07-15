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
    'ahmedabad': {
        'character': "A UNESCO World Heritage city — Gujarat's historic textile and trading hub, blending 600-year-old Sultanate-era mosques and step-wells with a modern commercial economy.",
        'landmarks': [
            {
                'name': 'Sabarmati Ashram',
                'category': 'heritage',
                'note': "Gandhi's residence from 1917 and launch point of the 1930 Dandi Salt March.",
            },
            {
                'name': 'Sidi Saiyyed Mosque',
                'category': 'heritage',
                'note': "1573 mosque famous for its carved stone 'tree of life' jali window.",
            },
            {
                'name': 'Bhadra Fort',
                'category': 'heritage',
                'note': 'Citadel built by founder Sultan Ahmed Shah in 1411.',
            },
            {
                'name': 'Manek Chowk',
                'category': 'shopping',
                'note': "Morning vegetable market, daytime jewelry bazaar (India's 2nd-largest), and a legendary street-food night market after 9:30pm.",
            },
            {
                'name': 'Kankaria Lake',
                'category': 'garden',
                'note': '1451 circular lake with an island garden, zoo, and evening laser show.',
            },
            {
                'name': 'Law Garden',
                'category': 'shopping',
                'note': 'Evening street market ringing the garden, famous for chaniya choli and mirror-work.',
            },
            {
                'name': 'Sarkhej Roza',
                'category': 'heritage',
                'note': '15th-century Sufi mosque-tomb complex, an Indo-Saracenic masterpiece.',
            },
            {
                'name': 'Hutheesing Jain Temple',
                'category': 'religious',
                'note': 'Elegant 1848 white-marble Jain temple noted for detailed stone carving.',
            },
            {
                'name': 'Calico Museum of Textiles',
                'category': 'museum',
                'note': 'World-renowned museum of historic Indian textiles, founded 1949.',
            },
        ],
        'food': [
            {
                'dish': 'Fafda-Jalebi',
                'where': 'Chandravilas (120+ years) or Sunday-morning stalls citywide',
                'note': 'Crispy gram-flour fafda with hot sugar-syrup jalebi, a weekend breakfast tradition.',
            },
            {
                'dish': 'Khaman',
                'where': 'Das Khaman (since 1922) and street vendors citywide',
                'note': 'Spongy steamed gram-flour snack topped with mustard tempering and sev.',
            },
            {
                'dish': 'Manek Chowk night-market food',
                'where': 'Manek Chowk, after dark',
                'note': 'Pav bhaji, dosas, bhajiyas, maska buns and kulfi till late night.',
            },
            {
                'dish': 'Bhatiyar Gali kebabs & haleem',
                'where': 'Bhatiyar Gali, near Kalupur/Astodia',
                'note': 'A ~600-year-old lane of Mughlai-style non-veg street food.',
            },
            {
                'dish': 'Gathiya',
                'where': 'Gayatri Ganthiya, Iscon Gathiya, Sunday breakfast stalls',
                'note': 'Deep-fried chickpea-flour snack, often eaten with fafda-jalebi.',
            },
            {
                'dish': 'Rajwadi Kulfi',
                'where': 'Havmor (Ahmedabad-founded, 70+ years)',
                'note': 'Rich cardamom-and-milk kulfi, often served as kulfi-falooda.',
            },
        ],
    },
    'nadiad': {
        'character': "A temple town in Kheda district, nicknamed 'Sakshar Nagari' (city of the literate) for its 19th-20th century Gujarati writers, and best known locally for the Santram Mandir.",
        'landmarks': [
            {
                'name': 'Santram Mandir',
                'category': 'religious',
                'note': "Central shrine to Santram Maharaj, Nadiad's most-visited religious site with daily aarti and full-moon celebrations.",
            },
            {
                'name': 'Mai Mandir',
                'category': 'religious',
                'note': "Large Shiva temple (~135ft spire) near Nadiad Junction, among Gujarat's tallest.",
            },
            {
                'name': 'Shri Atmasiddhishastra Rachnabhoomi',
                'category': 'heritage',
                'note': 'Memorial where Jain poet-mystic Shrimad Rajchandra composed his philosophical text in 1895.',
            },
            {
                'name': 'Dahilaxmi Library',
                'category': 'heritage',
                'note': "Historic library founded by writer Mansukhram Tripathi — emblematic of the 'Sakshar' reputation.",
            },
        ],
        'food': [
            {
                'dish': 'Nadiadi Bhusu',
                'where': 'Chandra Snacks (since 1946) and other legacy namkeen makers',
                'note': "Nadiad's signature ten-ingredient fried snack mix (sev, gathiya, chevdo, fulwadi, bundi...) — sold across Gujarat but originates here.",
            },
            {
                'dish': 'Gathiya & sev farsan',
                'where': 'Farsan shops on/around Santram Road',
                'note': 'Deep-fried gram-flour strands, a core ingredient of Nadiadi Bhusu, also sold standalone.',
            },
        ],
    },
    'anand': {
        'character': "India's dairy-cooperative capital — 'Milk Capital of India', home to Amul and the NDDB, birthplace of the White Revolution.",
        'landmarks': [
            {
                'name': 'Amul Dairy Plant',
                'category': 'attraction',
                'note': "Bookable guided tour of Asia's largest dairy cooperative plant, with a museum on the milk revolution (book via visit.amul.in).",
            },
            {
                'name': 'Dr. Verghese Kurien Memorial Museum',
                'category': 'museum',
                'note': "Honors the 'Father of the White Revolution' with his awards and interactive exhibits.",
            },
            {
                'name': 'BAPS Shri Swaminarayan Mandir',
                'category': 'religious',
                'note': 'Six-storeyed marble-and-sandstone lotus-shaped temple with nine inner domes.',
            },
            {
                'name': 'ISKCON Vallabh Vidyanagar',
                'category': 'religious',
                'note': 'Hare Krishna temple with daily evening aarati and prasad.',
            },
        ],
        'food': [
            {
                'dish': 'Amul dairy products',
                'where': 'Amul Parlour outlets across Anand, near the plant',
                'note': 'Anand is the literal home of Amul — fresh butter, cheese, and ice cream, and the parlours are themselves a visited spot.',
            },
            {
                'dish': 'Darbar Mug Pulao',
                'where': 'Darbar Mug Pulao & Fast Food, Vallabh Vidyanagar',
                'note': 'A decades-old Anand institution — spiced sprouted-moong pulao with curd and chutney.',
            },
            {
                'dish': 'Kachori',
                'where': 'Street vendors across Anand',
                'note': 'Deep-fried pastry filled with spiced lentil/pea mixture, a staple Anand street food.',
            },
        ],
    },
    'vadodara': {
        'character': 'Royal heritage city (Baroda) — former capital of the princely Gaekwad dynasty, known for grand Indo-Saracenic architecture and gardens.',
        'landmarks': [
            {
                'name': 'Laxmi Vilas Palace',
                'category': 'heritage',
                'note': '1890 Indo-Saracenic palace, roughly 4x the size of Buckingham Palace, still privately owned by the Gaekwad family.',
            },
            {
                'name': 'Sayaji Baug',
                'category': 'garden',
                'note': 'Sprawling garden with a zoo, planetarium, health museum, and toy train.',
            },
            {
                'name': 'Baroda Museum & Picture Gallery',
                'category': 'museum',
                'note': "Founded 1887 inside Sayaji Baug, modeled on London's V&A Museum.",
            },
            {
                'name': 'EME Temple',
                'category': 'religious',
                'note': '1966 geodesic-dome temple blending symbols of five major religions.',
            },
            {
                'name': 'Sursagar Lake',
                'category': 'attraction',
                'note': '18th-century city-center lake with a 111ft gold-gilded Shiva statue.',
            },
            {
                'name': 'Kirti Mandir',
                'category': 'heritage',
                'note': '1936 Gaekwad family cenotaph with wall paintings by Nandalal Bose.',
            },
        ],
        'food': [
            {
                'dish': 'Sev Usal',
                'where': 'Mahakali Sev Usal, near Kirti Stambh/Polo Ground (since 1988)',
                'note': "Vadodara's signature dish — a white-pea curry topped with crispy sev and onions.",
            },
            {
                'dish': 'Sev Khamani',
                'where': 'Street stalls in the Mandvi/Raopura area',
                'note': 'Crumbled, tangy-sweet khaman garnished with sev, onion, and pomegranate.',
            },
            {
                'dish': 'Jalebi & Malpua',
                'where': 'Mandvi street-food area',
                'note': "Classic sweets sold in the crowded lanes of one of Vadodara's oldest food hubs.",
            },
        ],
    },
    'surat': {
        'character': "India's diamond-cutting and textile capital — a fast-growing industrial hub on the Tapi River, polishing ~80-90% of the world's diamonds.",
        'landmarks': [
            {
                'name': 'Surat Castle',
                'category': 'heritage',
                'note': '16th-century riverside fortress built by Sultan Mahmud III.',
            },
            {
                'name': 'Chintamani Jain Temple',
                'category': 'religious',
                'note': "400-year-old Jain temple near Rani Talab, famed for wood carvings ('Wooden Temple').",
            },
            {
                'name': 'Dutch Garden',
                'category': 'garden',
                'note': 'European-style landscaped garden in Nanpura with fountains and lawns.',
            },
            {
                'name': 'Surat Diamond Bourse',
                'category': 'attraction',
                'note': "Opened 2023 — the world's largest office building (7.1M sq ft), built for the diamond trade.",
            },
            {
                'name': 'Surat Textile Market, Ring Road',
                'category': 'shopping',
                'note': "One of India's busiest wholesale saree and fabric trading markets.",
            },
            {
                'name': 'Dumas Beach',
                'category': 'other',
                'note': 'Arabian Sea beach ~21km out, known for unusual black iron-rich sand.',
            },
            {
                'name': 'Sarthana Nature Park',
                'category': 'garden',
                'note': "81-acre park, Gujarat's largest zoo, home to lions, tigers and leopards.",
            },
        ],
        'food': [
            {
                'dish': 'Locho',
                'where': 'Sagrampura / Chowk Bazar (e.g. JP Khaman, Gopal Locho)',
                'note': 'Soft, loose gram-flour and yogurt steamed snack — a Surat-invented street food.',
            },
            {
                'dish': 'Surti Undhiyu',
                'where': 'Widely sold across Surat, especially winter street stalls',
                'note': 'Mixed-vegetable dish slow-cooked upside-down in earthen pots — a signature winter dish.',
            },
            {
                'dish': 'Ghari',
                'where': 'Shah Jamnadas C. Ghariwala (est. 1899) and old-city sweet shops',
                'note': 'Round sweet of mawa, ghee and sugar in a puri shell, tied to the Chandani Padvo festival.',
            },
            {
                'dish': 'Ponk',
                'where': 'Seasonal street vendors citywide, Dec-Jan only',
                'note': 'Green sorghum seeds harvested only in the coldest winter weeks.',
            },
        ],
    },
    'rajkot': {
        'character': "Saurashtra's largest commercial hub — royal-era architecture, deep Gandhi heritage (his childhood home and school are here), and lively evening street food.",
        'landmarks': [
            {
                'name': 'Watson Museum',
                'category': 'museum',
                'note': "One of Gujarat's oldest museums (1888) with Kathiawar royal and Indus Valley relics.",
            },
            {
                'name': 'Kaba Gandhi No Delo',
                'category': 'heritage',
                'note': "Gandhi's ancestral family home (1881-1887), now a pictorial museum of his life.",
            },
            {
                'name': 'Mahatma Gandhi Museum',
                'category': 'museum',
                'note': 'Housed in the former Alfred High School where Gandhi studied — 39 galleries.',
            },
            {
                'name': 'Jubilee Garden',
                'category': 'garden',
                'note': 'Central park in Lohana Para with a royal cenotaph, home to the Watson Museum.',
            },
            {
                'name': 'Rotary Dolls Museum',
                'category': 'museum',
                'note': 'Over 1,600 dolls from 100+ countries — listed in the Limca Book of Records.',
            },
            {
                'name': 'Jagat Mandir',
                'category': 'religious',
                'note': '168-foot marble temple (1934) modeled on Belur Math, within Ramakrishna Ashram.',
            },
        ],
        'food': [
            {
                'dish': 'Rajkot Penda',
                'where': 'Jay Siyaram Pendawala, Panchnath Main Road',
                'note': "Rajkot's signature milk sweet, distinct for being made from plain milk.",
            },
            {
                'dish': 'Gujarati farsan (dhokla, khaman, khandvi)',
                'where': 'Sadar Bazar street stalls',
                'note': "Classic steamed/fried Gujarati snacks widely sold across the city's markets.",
            },
            {
                'dish': 'Chaat & evening snacks',
                'where': 'C.S. Soni Chowk',
                'note': 'A well-known local hangout chowk for Gujarati snacks, busiest in the evenings.',
            },
        ],
    },
    'gandhinagar': {
        'character': 'The planned, green administrative capital of Gujarat, built in the 1960s along the Sabarmati River, known for wide tree-lined sectors and government institutions.',
        'landmarks': [
            {
                'name': 'Swaminarayan Akshardham',
                'category': 'religious',
                'note': '23-acre BAPS temple complex in Sector 20, built from pink sandstone with no steel/iron, plus exhibition halls and an IMAX theater. (A separate, smaller Akshardham exists in Ahmedabad — do not conflate the two.)',
            },
            {
                'name': 'Adalaj Stepwell',
                'category': 'heritage',
                'note': 'Five-story 15th-century Solanki-style stepwell in Adalaj village, ~5km from the city.',
            },
            {
                'name': 'Indroda Nature Park',
                'category': 'museum',
                'note': "400-hectare park with India's only dinosaur fossil park and museum, plus a zoo.",
            },
            {
                'name': 'Sarita Udyan',
                'category': 'garden',
                'note': "Gandhinagar's oldest and largest garden (30 acres) with a deer park, boating, toy train.",
            },
            {
                'name': 'Mahatma Mandir',
                'category': 'attraction',
                'note': '34-acre convention centre inspired by Gandhi, host of Vibrant Gujarat summits.',
            },
            {
                'name': 'Dandi Kutir Museum',
                'category': 'museum',
                'note': 'Experiential Gandhi museum shaped as a 41-meter salt-mound dome with holography exhibits.',
            },
        ],
        'food': [
            {
                'dish': 'Dhokla',
                'where': 'Sector 21 street-food area (e.g. Pooja Parlour & Fast Food Centre)',
                'note': "Steamed, fermented gram-flour cake — cited as Gandhinagar's most-ordered street item.",
            },
            {
                'dish': 'Fafda-Jalebi',
                'where': 'Sector 21 market',
                'note': 'Classic Gujarati combo of crispy fafda strips with sweet, syrup-soaked jalebi.',
            },
            {
                'dish': 'Swaminarayan Khichdi',
                'where': 'Premvati Food Court, inside the Akshardham complex',
                'note': 'Self-service canteen serving satvik (no onion/garlic) fare; the khichdi is a signature item.',
            },
        ],
    },
    'jamnagar': {
        'character': "A coastal former princely-state capital on the Gulf of Kutch, known as 'Chhoti Kashi' for its dense cluster of temples, and as India's 'Brass City'.",
        'landmarks': [
            {
                'name': 'Lakhota Fort',
                'category': 'heritage',
                'note': '19th-century fort on an island in Lakhota Lake, now a museum of regional artifacts.',
            },
            {
                'name': 'Ranmal Lake',
                'category': 'garden',
                'note': 'Large artificial lake in the city center, popular for walks and boating.',
            },
            {
                'name': 'Bala Hanuman Temple',
                'category': 'religious',
                'note': 'Guinness World Record holder for unbroken 24-hour Ram-dhun chanting since 1964.',
            },
            {
                'name': 'Khambhalia Gate',
                'category': 'heritage',
                'note': '17th-century surviving city gate, restored as a heritage gallery in 2016.',
            },
            {
                'name': 'Pratap Vilas Palace',
                'category': 'heritage',
                'note': 'Indo-Saracenic royal palace (1907-1915) notable for its three glass domes.',
            },
            {
                'name': 'Willingdon Crescent',
                'category': 'shopping',
                'note': 'European-style arcaded market crescent, today a shopping hub famed for bandhani textiles.',
            },
        ],
        'food': [
            {
                'dish': 'Jamnagari Ghughra',
                'where': 'Street vendors and farsan shops citywide (evening)',
                'note': 'Deep-fried, pleated dumpling stuffed with spiced potato, white peas, ginger and garlic.',
            },
            {
                'dish': 'Jamnagari Kachori',
                'where': 'Kachori Junction / Shreeji Pavan Kachori (some since 1979)',
                'note': 'Crisp, layered deep-fried kachori with moong dal stuffing, sold with aloo sabzi.',
            },
            {
                'dish': 'Farali/Lilo Chevdo',
                'where': 'Local farsan shops (e.g. Halar Food Products)',
                'note': 'Savory fried gram-flour/sev-based snack mix, a long-standing local farsan specialty.',
            },
        ],
    },
    'junagadh': {
        'character': 'A historic Nawabi-era city at the foot of the sacred Girnar hill, and the traditional gateway to Gir Forest, home of the Asiatic lion.',
        'landmarks': [
            {
                'name': 'Uparkot Fort',
                'category': 'heritage',
                'note': 'Ancient hilltop fort (roots to 319 BCE) with Buddhist caves and the Adi-Kadi Vav stepwell.',
            },
            {
                'name': 'Girnar Hill',
                'category': 'religious',
                'note': "Sacred Jain/Hindu pilgrimage mountain with ~9,999-10,000 steps past Ambaji Temple and Gorakhnath Peak, Gujarat's highest point.",
            },
            {
                'name': 'Mahabat Maqbara',
                'category': 'heritage',
                'note': 'Ornate 1892 royal mausoleum blending Indo-Islamic and Gothic styles, with silver doors.',
            },
            {
                'name': 'Sakkarbaug Zoological Garden',
                'category': 'attraction',
                'note': "India's oldest zoo (1863), a major Asiatic lion breeding center.",
            },
            {
                'name': 'Darbar Hall Museum',
                'category': 'museum',
                'note': 'Former royal court of the Babi Nawab dynasty, displaying thrones, weapons and costumes.',
            },
            {
                'name': 'Willingdon Dam',
                'category': 'garden',
                'note': 'British-era reservoir at the base of the Girnar hills, a popular picnic/birdwatching spot.',
            },
        ],
        'food': [
            {
                'dish': 'Sev Khamani',
                'where': 'Street stalls and local eateries citywide',
                'note': 'Crumbled khaman dhokla tossed with sev, chana mixture and pomegranate.',
            },
            {
                'dish': 'Lilva Kachori',
                'where': 'Local sweet/snack shops (winter season)',
                'note': 'Kachori stuffed with fresh green pigeon-peas — a seasonal Saurashtra favorite.',
            },
            {
                'dish': 'Bhajiya',
                'where': 'Rokadiya, Junagadh',
                'note': 'Deep-fried gram-flour fritters; Rokadiya is a well-known local bhajiya spot.',
            },
            {
                'dish': 'Basundi',
                'where': 'Local sweet shops',
                'note': 'Thickened, sweetened milk dessert flavored with cardamom and nuts.',
            },
        ],
    },
    'bhavnagar': {
        'character': 'A former princely-state capital (Gohil Rajput dynasty) on the Gulf of Khambhat, with royal heritage and a signature street-food invention.',
        'landmarks': [
            {
                'name': 'Takhteshwar Temple',
                'category': 'religious',
                'note': 'Hilltop all-white-marble Shiva temple (1893) with panoramic views to the Gulf.',
            },
            {
                'name': 'Nilambag Palace',
                'category': 'heritage',
                'note': 'Former royal residence (1879) in Anglo-Indian style, now a heritage hotel.',
            },
            {
                'name': 'Darbargadh',
                'category': 'heritage',
                'note': 'Early-1800s royal palace-gate complex of the Bhavnagar princely state.',
            },
            {
                'name': 'Gangajaliya Talav',
                'category': 'garden',
                'note': 'Historic lake with a white-marble Jain temple pavilion (Ganga Deri) on the water.',
            },
            {
                'name': 'Victoria Park',
                'category': 'garden',
                'note': 'Sprawling ~500-acre historic urban forest established in 1888.',
            },
            {
                'name': 'Barton Library & Museum',
                'category': 'museum',
                'note': "One of Kathiawad's oldest libraries (1882), with an attached Gandhi Smriti museum.",
            },
        ],
        'food': [
            {
                'dish': 'Pav Gathiya',
                'where': 'Lachhubhai Ganthiyawala, Ghogha Circle (since 1951)',
                'note': "Bhavnagar's own street-food invention — crispy gathiya with soft pav, onion and chutney.",
            },
            {
                'dish': 'Bhavnagari Gathiya',
                'where': 'Lachhubhai, Manubhai Gathiyawala, Jay Gopal Farsan House',
                'note': 'Crunchy fried gram-flour snack that gave this Gujarati farsan style its national name.',
            },
            {
                'dish': 'Fafda-Jalebi',
                'where': 'Citywide breakfast stalls',
                'note': 'Classic Gujarati breakfast pairing, called an iconic Bhavnagar morning staple.',
            },
        ],
    },
    'bharuch': {
        'character': "One of India's oldest continuously inhabited cities — a historic Narmada-river port (ancient 'Barygaza') of temples, gates, and bazaars.",
        'landmarks': [
            {
                'name': 'Bhrigu Rishi Temple',
                'category': 'religious',
                'note': 'Riverside 17th-century Maratha-built temple honoring the sage the city is named for.',
            },
            {
                'name': 'Jama Masjid',
                'category': 'heritage',
                'note': '14th-century mosque built partly from the remains of an earlier Jain temple.',
            },
            {
                'name': 'Bharuch Fort',
                'category': 'heritage',
                'note': '~1,000-year-old hilltop fort overlooking the Narmada, with colonial-era additions.',
            },
            {
                'name': 'Kabirvad',
                'category': 'garden',
                'note': 'Narmada island dominated by a sprawling 2.5+ acre banyan tree linked to saint Kabir.',
            },
            {
                'name': 'Golden Bridge',
                'category': 'heritage',
                'note': 'British-era iron bridge (1877-1881) over the Narmada linking Bharuch and Ankleshwar.',
            },
        ],
        'food': [
            {
                'dish': 'Khari Sing (roasted salted peanuts)',
                'where': 'Bharuch region',
                'note': 'Locally roasted peanuts, widely marketed as a genuine Bharuch specialty.',
            },
            {
                'dish': 'Bharuchi seafood',
                'where': 'Home-style Bharuchi cooking',
                'note': "Pomfret, Bombay duck, prawns with rotli/rotla — reflecting the town's river-port Surti-Kathiyawadi-Parsi culinary crossroads identity.",
            },
        ],
    },
    'porbandar': {
        'character': 'A coastal Kathiyawadi port town, best known worldwide as the birthplace of Mahatma Gandhi, with a working fishing harbor and maritime history.',
        'landmarks': [
            {
                'name': 'Kirti Mandir',
                'category': 'heritage',
                'note': "Museum and memorial at Gandhi's ancestral birth house, inaugurated 1950.",
            },
            {
                'name': 'Sudama Mandir',
                'category': 'religious',
                'note': "White-marble temple (1902-1907) dedicated to Sudama, Krishna's childhood friend.",
            },
            {
                'name': 'Chowpatty Beach',
                'category': 'beach',
                'note': 'Clean, tranquil Arabian Sea beach near the city center.',
            },
            {
                'name': 'Porbandar Bird Sanctuary',
                'category': 'attraction',
                'note': 'Wetland sanctuary near Chowpatty with ~150-200 resident/migratory bird species.',
            },
            {
                'name': 'Sartanji Choro',
                'category': 'heritage',
                'note': "Three-storey fort/palace at the city's highest point, built by Rana Sartanji.",
            },
            {
                'name': 'Nehru Planetarium',
                'category': 'museum',
                'note': 'Astronomy museum and planetarium inaugurated 1972 by Indira Gandhi.',
            },
        ],
        'food': [
            {
                'dish': 'Khajali',
                'where': 'Citywide, dozens of dedicated snack-maker shops',
                'note': "Porbandar's signature snack — flaky ghee-fried wheat-flour biscuits, sweet or masala; production is concentrated almost exclusively in Porbandar.",
            },
            {
                'dish': 'Pomfret & prawns (seafood)',
                'where': 'Local seafood restaurants and the fishing harbor market',
                'note': "Porbandar is one of Gujarat's leading seafood hubs, grilled or in spicy masala.",
            },
        ],
    },
    'dwarka': {
        'character': "One of Hinduism's holiest pilgrimage towns — part of the Char Dham circuit and traditionally Krishna's ancient coastal kingdom.",
        'landmarks': [
            {
                'name': 'Dwarkadhish Temple',
                'category': 'religious',
                'note': "Main shrine to Krishna as 'Lord of Dwarka', a five-storied temple on 72 pillars.",
            },
            {
                'name': 'Nageshvara Jyotirlinga Temple',
                'category': 'religious',
                'note': 'One of the 12 sacred Jyotirlingas of Shiva, ~18km from Dwarka, with an 80ft Shiva statue.',
            },
            {
                'name': 'Rukmini Devi Temple',
                'category': 'religious',
                'note': "Richly carved 12th-19th century temple to Krishna's queen Rukmini.",
            },
            {
                'name': 'Bet Dwarka',
                'category': 'heritage',
                'note': "Island ~30km out, reached by boat, traditionally held as Krishna's residence.",
            },
            {
                'name': 'Gomti Ghat',
                'category': 'heritage',
                'note': 'Riverside ghat where the Gomti River meets the sea, lined with shrines.',
            },
            {
                'name': 'Dwarka Beach',
                'category': 'beach',
                'note': 'Arabian Sea beach near the main temple, known for calm sunrises and marine life.',
            },
        ],
        'food': [
            {
                'dish': 'Sattvik Temple Thali / Bhog',
                'where': 'Temple trust dining halls near Dwarkadhish Temple',
                'note': "Pure vegetarian meals without onion/garlic, reflecting the town's pilgrimage food culture.",
            },
            {
                'dish': 'Panchamrit and Peda',
                'where': 'Dwarkadhish Temple prasad counters',
                'note': 'The prasad distributed to devotees as part of the daily bhog.',
            },
        ],
    },
    'somnath': {
        'character': "A major Hindu pilgrimage town on the Saurashtra coast, built around the Somnath Jyotirlinga temple, with Veraval's fishing port nearby.",
        'landmarks': [
            {
                'name': 'Somnath Temple',
                'category': 'religious',
                'note': 'The first of the 12 Jyotirlingas of Shiva, rebuilt 1950, set on the Arabian Sea shore.',
            },
            {
                'name': 'Triveni Sangam',
                'category': 'heritage',
                'note': 'Sacred confluence of three rivers meeting the sea beside the temple.',
            },
            {
                'name': 'Bhalka Tirth',
                'category': 'heritage',
                'note': "Traditionally identified as where Krishna was struck by a hunter's arrow.",
            },
            {
                'name': 'Prabhas Patan Museum',
                'category': 'museum',
                'note': 'Archaeological museum displaying sculptures recovered from earlier temple versions.',
            },
            {
                'name': 'Somnath Beach',
                'category': 'beach',
                'note': 'Shoreline beside the temple, site of an evening sound-and-light show.',
            },
        ],
        'food': [
            {
                'dish': 'Temple prasad (gathiya, chikki, laddoo)',
                'where': 'Somnath Temple premises',
                'note': 'Traditional satvik snacks distributed to pilgrims.',
            },
            {
                'dish': 'Seafood',
                'where': 'Veraval fishing harbour, ~5km away',
                'note': "One of India's major fishing ports, distinct from the vegetarian pilgrim food at the temple.",
            },
        ],
    },
    'palanpur': {
        'character': 'A North Gujarat town historically ruled by Muslim Nawabs, today globally known as the ancestral home of the Palanpuri diamond-trade Jain community.',
        'landmarks': [
            {
                'name': 'Balaram Palace',
                'category': 'heritage',
                'note': 'Neo-classical hunting palace (1922-1936) on the Balaram River, now a heritage hotel.',
            },
            {
                'name': 'Kirti Stambh',
                'category': 'heritage',
                'note': "Commemorative 1918 tower engraved with the lineage of Palanpur's rulers.",
            },
            {
                'name': 'Pallaviya Parshwanath Temple',
                'category': 'religious',
                'note': 'Historic Jain temple whose idol reportedly survived the 2001 earthquake undamaged.',
            },
        ],
        'food': [
            {
                'dish': 'Kaju Katli & Mohanthal',
                'where': 'Alpahar Sweets, Palanpur',
                'note': 'Handcrafted ghee-based sweets from a well-known local confectionery.',
            },
        ],
    },
    'patan': {
        'character': 'A UNESCO-recognized historic capital of the Chaulukya (Solanki) dynasty, renowned for stepwell architecture and Patola weaving.',
        'landmarks': [
            {
                'name': 'Rani ki Vav',
                'category': 'heritage',
                'note': '11th-century subterranean stepwell, UNESCO World Heritage Site, depicted on the ₹100 note.',
            },
            {
                'name': 'Sahastralinga Talav',
                'category': 'heritage',
                'note': "11th-century artificial lake ('lake of a thousand lingams') on the Saraswati River.",
            },
            {
                'name': 'Panchasara Parshwanath Temple',
                'category': 'religious',
                'note': '8th-century Jain temple housing the historic Hemchandracharya manuscript library.',
            },
            {
                'name': 'Patan Patola Heritage Museum',
                'category': 'museum',
                'note': 'Documents the 900-year-old Patola double-ikat weaving tradition, with live demonstrations.',
            },
        ],
        'food': [
            {
                'dish': 'Devada (Sata)',
                'where': 'Bhagwati Sweet Mart and other local sweet shops',
                'note': "Crispy, flaky, layered sweet — Patan's signature traditional sweet for festivals/weddings.",
            },
            {
                'dish': 'Patola sarees (craft, not food)',
                'where': 'Salvi family workshops, Patan',
                'note': 'Hand-woven double-ikat silk sarees, a centuries-old technique preserved by the Salvi family.',
            },
        ],
    },
    'mehsana': {
        'character': 'A North Gujarat town best known as a major dairy-cooperative hub (home to Dudhsagar Dairy) with historically significant nearby temples.',
        'landmarks': [
            {
                'name': 'Simandhar Swami Jain Temple',
                'category': 'religious',
                'note': 'Prominent Jain pilgrimage temple in Mehsana town, built 1972, with a 146-inch idol.',
            },
            {
                'name': 'Dudhsagar Dairy',
                'category': 'attraction',
                'note': "India's largest cooperative dairy, headquartered in Mehsana — the town's Amul-like anchor.",
            },
            {
                'name': 'Modhera Sun Temple',
                'category': 'heritage',
                'note': '11th-century Sun Temple ~26-28km away in Mehsana district (see Modhera entry).',
            },
            {
                'name': 'Bahuchar Mata Temple, Becharaji',
                'category': 'religious',
                'note': 'Major Shakti Peeth ~35km away, a key pilgrimage site.',
            },
        ],
        'food': [
            {
                'dish': 'Doodhpak',
                'where': "Associated with Mehsana's dairy abundance",
                'note': 'Rich, slow-cooked full-fat milk rice pudding with cardamom, saffron and nuts.',
            },
            {
                'dish': 'Shrikhand',
                'where': 'Produced by Dudhsagar Dairy',
                'note': 'Strained-yogurt sweet flavored with saffron and cardamom, an actual dairy product line.',
            },
        ],
    },
    'bhuj': {
        'character': 'The historic, earthquake-rebuilt royal capital of Kutch, and the main gateway to the White Rann of Kutch and its craft villages.',
        'landmarks': [
            {
                'name': 'Aina Mahal',
                'category': 'heritage',
                'note': '18th-century royal residence famed for ornate mirror-glasswork walls and ceilings.',
            },
            {
                'name': 'Prag Mahal',
                'category': 'heritage',
                'note': '19th-century Indo-European palace blending Italian Gothic and Rajput styles.',
            },
            {
                'name': 'Great Rann of Kutch (White Desert)',
                'category': 'attraction',
                'note': "One of the world's largest salt deserts; Bhuj is the gateway, Dhordo ~80-90km away.",
            },
            {
                'name': 'Bhujodi Village',
                'category': 'shopping',
                'note': 'Weaving village ~8km out, home to the distinctive Bhujodi handloom textile style.',
            },
            {
                'name': 'Nirona Village',
                'category': 'shopping',
                'note': 'Craft village known for rare Rogan art (heated castor-oil paste painting).',
            },
            {
                'name': 'Bhujia Fort',
                'category': 'heritage',
                'note': 'Hilltop fort (1715-1718) overlooking Bhuj city, popular for sunrise/sunset views.',
            },
        ],
        'food': [
            {
                'dish': 'Kutchi Dabeli',
                'where': 'Originated in Mandvi, Kutch (1960s, Keshavji Gabha Chudasama)',
                'note': "Spiced mashed-potato in a pav bun with chutneys, peanuts and sev — Kutch's signature street food.",
            },
            {
                'dish': 'Bajra Rotla with lasan chutney',
                'where': 'Rural Kutch generally',
                'note': 'Thick millet flatbread served hot with white butter, jaggery, and fiery garlic chutney.',
            },
            {
                'dish': 'Adadiya',
                'where': 'Kutch, winter-only',
                'note': 'Urad-dal-and-ghee-based sweet traditionally made and eaten only in winter.',
            },
        ],
    },
    'valsad': {
        'character': 'A coastal district headquarters town in south Gujarat on the Arabian Sea, known for its black-sand beach and Parsi food influence.',
        'landmarks': [
            {
                'name': 'Tithal Beach',
                'category': 'beach',
                'note': '~6km black-sand beach lined with palm trees, popular for water sports.',
            },
            {
                'name': 'Parnera Hill (Parnera Fort)',
                'category': 'heritage',
                'note': 'Hilltop fort linked by legend to Shivaji, with several temples and a trekking spot.',
            },
            {
                'name': 'Bilpudi Waterfalls',
                'category': 'nature',
                'note': 'Waterfall ~10km from Dharampur, a nature excursion near Valsad.',
            },
        ],
        'food': [
            {
                'dish': 'Parsi dishes',
                'where': 'Parsi restaurants in town',
                'note': "Salli per eedu, kheema pav, mutton pulao dar — reflecting the region's Parsi community.",
            },
            {
                'dish': 'Tithal beach street food',
                'where': 'Tithal beach stalls',
                'note': 'Bhel puri, pani puri, dabeli, bhajiya, roasted corn — a lively beachfront food hub.',
            },
        ],
    },
    'navsari': {
        'character': "A historic south Gujarat town regarded as one of India's most significant Parsi settlements, and the birthplace of Jamsetji Tata.",
        'landmarks': [
            {
                'name': 'J.N. Tata Birthplace',
                'category': 'heritage',
                'note': 'Restored house in Dasturwad where Jamsetji Tata was born in 1839, now a free museum.',
            },
            {
                'name': 'Vadi Dar-e-Meher',
                'category': 'religious',
                'note': '~850-year-old Zoroastrian fire temple complex where Jamsetji Tata was ordained a priest.',
            },
            {
                'name': 'Bhagarsath Anjuman Atash Behram',
                'category': 'religious',
                'note': "One of India's oldest Atash Behram fire temples, consecrated in 1765.",
            },
            {
                'name': 'Meherjirana Library',
                'category': 'museum',
                'note': "Founded 1874, one of the world's richest collections of books on Zoroastrianism.",
            },
            {
                'name': 'Dandi Beach',
                'category': 'beach',
                'note': "Coastal village where Gandhi's 1930 Salt March ended, marked by a 2019 memorial.",
            },
        ],
        'food': [
            {
                'dish': 'Dhansak',
                'where': 'Parsi homes/restaurants',
                'note': 'The signature Parsi dish of lentils and meat cooked together with caramelized rice.',
            },
            {
                'dish': 'Brun pav',
                'where': "Navsari's century-old Parsi bakeries",
                'note': 'Crusty-outside, soft-inside bread, a Parsi breakfast staple.',
            },
            {
                'dish': 'Kolah pickles and cane vinegar',
                'where': 'E.F. Kolah & Sons, Bamji Street (since 1885)',
                'note': 'A Parsi condiment maker famous for cane vinegar and pickles like gharab nu achar.',
            },
        ],
    },
    'diu': {
        'character': "A small former Portuguese colonial enclave off Gujarat's coast — whitewashed churches, a seafront fort, and seafood-forward cuisine.",
        'landmarks': [
            {
                'name': 'Diu Fort',
                'category': 'heritage',
                'note': 'Massive Portuguese fortress built 1535, perched on the coast with sweeping sea views.',
            },
            {
                'name': "St. Paul's Church",
                'category': 'religious',
                'note': 'c.1601-1610 Baroque church, considered one of the finest examples in India.',
            },
            {
                'name': 'Nagoa Beach',
                'category': 'beach',
                'note': "Diu's most popular beach, horseshoe-shaped with calm, palm-fringed waters.",
            },
            {
                'name': 'Gangeshwar Mahadev Temple',
                'category': 'religious',
                'note': 'Seaside cave temple with five Shiva lingas naturally washed by tidal sea waves.',
            },
            {
                'name': 'Zampa Gateway',
                'category': 'heritage',
                'note': 'Carved Portuguese-era gateway marking the entrance to the old fort area.',
            },
        ],
        'food': [
            {
                'dish': 'Crab Masala',
                'where': 'Seafood restaurants in Diu',
                'note': 'Fresh crab in a spicy masala, cited as a signature Diu delicacy.',
            },
            {
                'dish': 'Kalwa (Clam) Curry',
                'where': 'Seafood restaurants in Diu',
                'note': 'A distinctive Diu coastal specialty, not typical of vegetarian mainland Gujarat.',
            },
            {
                'dish': 'Fudina Machhi',
                'where': 'Seafood restaurants in Diu',
                'note': 'Fish marinated in mint and spices, grilled or fried.',
            },
        ],
    },
    'saputara': {
        'character': "Gujarat's only hill station, in the Sahyadri range within the tribal Dangs district — cool climate, a lake, and Bhil/Warli tribal culture.",
        'landmarks': [
            {
                'name': 'Saputara Lake',
                'category': 'nature',
                'note': "Central artificial lake with paddle-boat and rowboat rides, the town's main gathering spot.",
            },
            {
                'name': 'Sunrise Point',
                'category': 'nature',
                'note': 'Viewpoint reached by hike or ropeway, panoramic views over the Western Ghats at dawn.',
            },
            {
                'name': 'Sunset Point (Gandhi Shikhar)',
                'category': 'nature',
                'note': 'Popular vantage point overlooking the Dang forest, especially busy at sunset.',
            },
            {
                'name': 'Saputara Tribal Museum',
                'category': 'museum',
                'note': 'Displays art, weapons, jewelry and everyday objects of the local Bhil and Warli tribes.',
            },
            {
                'name': 'Artist Village',
                'category': 'shopping',
                'note': 'Craft center best known for traditional and tribal artifacts, including Warli paintings.',
            },
        ],
        'food': [
            {
                'dish': 'Lemongrass chai',
                'where': 'Town/lakeside stalls',
                'note': 'A locally grown-lemongrass-infused tea, a Saputara specialty.',
            },
            {
                'dish': 'Spicy boiled corn (bhutta)',
                'where': 'Roadside/lake-area stalls',
                'note': 'Hot, spiced boiled corn, especially popular in the cooler hill-station weather.',
            },
        ],
    },
    'ambaji': {
        'character': 'A major Hindu pilgrimage town on the Gujarat-Rajasthan border, centered on one of the 51 Shakti Peeths.',
        'landmarks': [
            {
                'name': 'Ambaji Temple',
                'category': 'religious',
                'note': 'White marble Shakti Peeth temple; the goddess is represented by a veiled yantra, not an idol.',
            },
            {
                'name': 'Gabbar Hill',
                'category': 'religious',
                'note': 'Sacred hilltop ~1.5km above town via 999 steps or ropeway, the original seat of the goddess.',
            },
            {
                'name': 'Mansarovar Kund',
                'category': 'heritage',
                'note': 'Large stepped water tank near the main temple, a traditional pilgrim bathing site.',
            },
            {
                'name': 'Shaktipeeth Parikrama',
                'category': 'heritage',
                'note': '~2.5-3km walking route lined with 51 symbolic replica temples of all the Shakti Peeths.',
            },
        ],
        'food': [
            {
                'dish': 'Mohanthal prasad',
                'where': 'Distributed by the Shree Arasuri Ambaji Mata Devasthan Trust',
                'note': "The temple's official besan-ghee-sugar sweet prasad — ~1.25 crore units sold annually.",
            },
        ],
    },
    'modhera': {
        'character': 'A small village whose identity is built almost entirely around a single 11th-century architectural masterpiece, the Sun Temple.',
        'landmarks': [
            {
                'name': 'Modhera Sun Temple',
                'category': 'heritage',
                'note': '11th-century (1026-27 CE) temple dedicated to Surya, built by King Bhima I of the Solanki dynasty.',
            },
            {
                'name': 'Surya Kund',
                'category': 'heritage',
                'note': 'Rectangular stepped tank in front of the temple with 108 miniature shrines.',
            },
            {
                'name': 'Sabha Mandap',
                'category': 'heritage',
                'note': 'Intricately carved pillared assembly hall between the Surya Kund and the main shrine.',
            },
        ],
        'food': [
            {
                'dish': 'Fafda-Jalebi',
                'where': 'General Gujarati region, not Modhera-specific',
                'note': 'Classic breakfast pairing — Modhera itself has no distinct signature dish beyond regional fare.',
            },
        ],
    },
    'palitana': {
        'character': "A pilgrimage town at the foot of Shatrunjaya Hill, famous worldwide as a 'city of temples' and widely reported as India's first vegetarian-only city.",
        'landmarks': [
            {
                'name': 'Shatrunjaya Hill temple complex',
                'category': 'religious',
                'note': 'A dense complex of roughly 900+ Jain shrines built up over ~900 years, on two hill ridges.',
            },
            {
                'name': 'Adinath Temple',
                'category': 'religious',
                'note': 'The largest and most splendid temple in the complex, dedicated to the first Jain Tirthankara.',
            },
            {
                'name': 'Chaumukhji Tunk',
                'category': 'religious',
                'note': 'Built 1618, features four images of Adinath facing the four cardinal directions.',
            },
        ],
        'food': [
            {
                'dish': 'Dal Dhokli',
                'where': 'General Gujarati/Jain-region dish, eaten locally',
                'note': "Wheat-flour dumplings simmered in lentil curry — Palitana's food culture is Jain-vegetarian, with thin distinct local-specialty data beyond regional fare.",
            },
        ],
    },
    'bilimora': {
        'character': 'Bilimora is a coastal Gujarati town with a Parsi heritage influence, known as a gateway to nearby beaches and waterfalls rather than a major tourist hub itself.',
        'landmarks': [
            {
                'name': 'Swaminarayan Temple',
                'category': 'religious',
                'note': 'Popular local temple known for its architecture and evening aarti',
            },
            {
                'name': 'Dandi Beach',
                'category': 'beach',
                'note': "Historic beach about 20 km away, site of Gandhi's famous Dandi March",
            },
            {
                'name': 'Dumas Beach',
                'category': 'beach',
                'note': 'Black-sand beach roughly 40 km away, popular for peaceful walks and sunsets',
            },
            {
                'name': 'Gira Waterfall',
                'category': 'nature',
                'note': 'Waterfall about 50 km away surrounded by lush greenery',
            },
            {
                'name': 'Sarthana Nature Park',
                'category': 'wildlife',
                'note': 'Nearby nature park making Bilimora a base for wildlife enthusiasts',
            },
        ],
        'food': [
            {
                'dish': 'Undhiyu',
                'where': 'local Gujarati eateries',
                'note': 'Traditional mixed seasonal vegetable dish with spices',
            },
            {
                'dish': 'Dhokla',
                'where': 'local eateries',
                'note': 'Steamed snack made from fermented rice and chickpea flour',
            },
            {
                'dish': 'Handvo',
                'where': 'local eateries',
                'note': 'Savory cake made with rice and lentils',
            },
            {
                'dish': 'Thepla',
                'where': 'local eateries',
                'note': 'Flatbread made from wheat flour and fenugreek leaves',
            },
            {
                'dish': 'Pav Bhaji',
                'where': 'street food stalls in town',
                'note': 'Popular street food, with locals debating who serves it best',
            },
            {
                'dish': 'Alphonso Mangoes',
                'where': 'local markets',
                'note': 'Town is noted for these mangoes, tied to its Parsi heritage',
            },
        ],
    },
    'cambay_khambhat': {
        'character': 'Khambhat (Cambay) is an ancient Gulf-of-Khambhat port town in Gujarat famed for its dramatic tidal range, Indo-Islamic architecture, and its 5,000-year-old living tradition of agate (akik) bead-making.',
        'landmarks': [
            {
                'name': 'Jami Mosque',
                'category': 'heritage',
                'note': "Built in 1325; one of Gujarat's finest examples of Indo-Islamic architecture, with intricately carved columns reused from ancient Jain temples",
            },
            {
                'name': 'Stambheshwar Mahadev Temple',
                'category': 'religious',
                'note': 'A rare Shiva temple that completely submerges twice daily during high tide, visible only during low tide',
            },
            {
                'name': 'Kavi Gokalnath Temple',
                'category': 'religious',
                'note': "Notable temple frequently listed among Khambhat's key religious sites",
            },
            {
                'name': 'Shree Lakshmi Narayan Mandir',
                'category': 'religious',
                'note': 'Prominent local temple in Khambhat',
            },
            {
                'name': "Mata Bhavani's Stepwell",
                'category': 'heritage',
                'note': "Intricate ancient stepwell reflecting Khambhat's archaeological heritage",
            },
            {
                'name': 'Agate (Akik) Bead-Making Workshops',
                'category': 'shopping',
                'note': 'Khambhat is the only place in India where the Harappan-era craft of agate bead-making survives as a living tradition, practiced for over 5,000 years',
            },
            {
                'name': 'Gulf of Khambhat',
                'category': 'nature',
                'note': 'Known for an extreme tidal range (up to thirty feet), rich marine biodiversity, and seasonal bird watching',
            },
        ],
        'food': [
            {
                'dish': 'Sutarfeni',
                'where': 'Local sweet shops across Khambhat',
                'note': 'Delicate shredded threads of fine flour and ghee topped with pistachios and cardamom; Khambhat is famous nationwide for this sweet',
            },
            {
                'dish': 'Halwasan',
                'where': 'Khambhat sweet exporters/shops',
                'note': 'Chewy, nutty sweet made from wheat, milk, and sugar with a rich, earthy taste',
            },
            {
                'dish': 'Khambhati Daabda',
                'where': 'Local street food stalls',
                'note': 'Stuffed potato fritters that are a popular indigenous street snack',
            },
            {
                'dish': 'Mag ni Kachori',
                'where': 'Local snack shops',
                'note': 'A must-try savory snack specific to Khambhat',
            },
            {
                'dish': 'Shrikhand',
                'where': 'Local sweet manufacturers',
                'note': 'A favorite sweet among Khambhatis, noted for its unique local preparation',
            },
        ],
    },
    'anjar': {
        'character': 'Anjar is the oldest town in Kutch, a historic Gujarati market town over 1,400 years old known for its temples, craftsmanship, and street food heritage.',
        'landmarks': [
            {
                'name': 'Jesal Toral Samadhi',
                'category': 'religious',
                'note': "resting place / shrine of the folk-legend lovers Jesal and Toral, the town's most famous site",
            },
            {
                'name': 'Madhavrai Temple',
                'category': 'religious',
                'note': "one of Anjar's ancient temples",
            },
            {
                'name': "Amba Mata's Shrine",
                'category': 'religious',
                'note': 'historic shrine dedicated to the goddess Amba',
            },
            {
                'name': 'MacMurdo Bungalow',
                'category': 'heritage',
                'note': 'state-protected colonial-era monument noted for its wall paintings of Lord Ram and Krishna',
            },
            {
                'name': 'Anjar Fort',
                'category': 'heritage',
                'note': 'historic fort on the edge of town offering a glimpse into the past',
            },
        ],
        'food': [
            {
                'dish': 'Dabeli',
                'where': 'local street stalls',
                'note': "Anjar's most iconic dish - spiced mashed potato in a bun, garnished with pomegranate seeds/peanuts and chutneys",
            },
            {
                'dish': 'Gher',
                'where': 'local eateries',
                'note': "traditional local specialty for which Anjar's cuisine is known",
            },
            {
                'dish': 'Kutchi Pakwan',
                'where': 'local bakeries/shops',
                'note': 'crispy fried flatbread snack associated with the region',
            },
            {
                'dish': 'Kutchi Khaja',
                'where': 'local sweet shops',
                'note': 'layered fried sweet snack originating from the Kutch region',
            },
            {
                'dish': 'Peda',
                'where': 'local sweet shops',
                'note': 'traditional milk-based sweet popular in the area',
            },
            {
                'dish': 'Farsan',
                'where': 'local shops',
                'note': 'savory gram-flour snacks like sev and pakoras, popular accompaniment or standalone treat',
            },
        ],
    },
    'amreli': {
        'character': "Amreli is a modest Saurashtra district town in Gujarat known for its old temples, a heritage clock tower and palace, and proximity to Gir's wildlife and the Khodiyar Dam.",
        'landmarks': [
            {
                'name': 'Nagnath Temple',
                'category': 'religious',
                'note': '203-year-old Shiva temple built in 1802 in the middle of Amreli city',
            },
            {
                'name': 'Kamnath and Trimbaknath Temples',
                'category': 'religious',
                'note': 'among the oldest temples in the city/district',
            },
            {
                'name': 'Rajmahal Palace',
                'category': 'heritage',
                'note': 'about 170-year-old heritage palace built by the Gaikwad kings of Vadodara',
            },
            {
                'name': 'Clock Tower',
                'category': 'heritage',
                'note': 'historic edifice erected in the Gaikwad dynasty era, a landmark of old Amreli',
            },
            {
                'name': 'Khodiyar Dam',
                'category': 'nature',
                'note': 'largest dam in Gujarat (75 ft high) built across the Shetrunji river, considered the best tourist spot near Amreli',
            },
            {
                'name': 'Gir Sanctuary (nearby)',
                'category': 'wildlife',
                'note': "world's only home of the Asiatic Lion, plus panthers, hyena, chinkara and other wildlife",
            },
            {
                'name': 'Shiyalbet',
                'category': 'beach',
                'note': 'small village surrounded by the Arabian Sea in Jafrabad taluka, known for its coastal setting',
            },
        ],
        'food': [
            {
                'dish': 'Gathiya',
                'where': 'street stalls throughout Amreli',
                'note': 'deep-fried gram-flour snack eaten fresh with chutney or kadhi',
            },
            {
                'dish': 'Oondhiya',
                'where': 'local Gujarati/Kathiyawadi eateries',
                'note': 'spicy mixed-vegetable dish cooked in an earthenware pot',
            },
            {
                'dish': 'Thepla and Khaman Dhokla',
                'where': 'home kitchens and local restaurants',
                'note': 'spiced wheat flatbread and steamed savory cake, everyday Gujarati staples',
            },
            {
                'dish': 'Tuver Dal with Vaghaar',
                'where': 'traditional thali restaurants',
                'note': 'pigeon-pea dal finished with a hot-oil spice tempering',
            },
            {
                'dish': 'Gharis and other sweets',
                'where': 'local sweet shops',
                'note': 'rich sweet made with dried fruit, butter and thickened milk, alongside Doodh Pak, Basundi and Shrikhand',
            },
        ],
    },
    'bhachau': {
        'character': 'Bhachau is a small Kutch district town, historically noted for its old fort and proximity to the Rann of Kutch, rather than being a major tourist hub itself.',
        'landmarks': [
            {
                'name': 'Bhachau Fort',
                'category': 'heritage',
                'note': "18th-century fort with historic architecture reflecting the region's royal past",
            },
            {
                'name': 'Bhachau Swaminarayan Mandir',
                'category': 'religious',
                'note': 'Local temple known for its intricate architecture and religious significance',
            },
            {
                'name': 'Balaram Palace (near Bhachau)',
                'category': 'heritage',
                'note': "Heritage royal palace converted into a hotel, offering a glimpse of Kutch's royal past",
            },
            {
                'name': 'Rann of Kutch (nearby)',
                'category': 'nature',
                'note': 'Vast salt marsh that turns into a white desert in the dry season; camel rides and jeep safaris available',
            },
            {
                'name': 'Kutch Desert Wildlife Sanctuary (nearby)',
                'category': 'wildlife',
                'note': 'Sanctuary near Bhachau with varied bird species and scenic sunsets over the salt marshes',
            },
        ],
        'food': [
            {
                'dish': 'Kutchhi Dabeli',
                'where': 'local street stalls',
                'note': 'Spicy potato snack with chutneys and special masala served in a bun, popular street food in the area',
            },
            {
                'dish': 'Khichu',
                'where': 'local area',
                'note': 'Traditional rice flour snack served with spicy chutney',
            },
            {
                'dish': 'Bhakri with Kadhi',
                'where': 'local area',
                'note': 'Flatbread common in Kutch, served with a tangy yogurt-based curry',
            },
            {
                'dish': 'Ghari',
                'where': 'local sweet shops',
                'note': 'Sweet made from ghee, sugar, and flour',
            },
            {
                'dish': 'Doodhpak',
                'where': 'local sweet shops',
                'note': 'Rich milk-based dessert',
            },
        ],
    },
    'bardoli': {
        'character': 'Bardoli is a historically significant Gujarati town, best known as the site of the 1928 Bardoli Satyagraha led by Sardar Vallabhbhai Patel.',
        'landmarks': [
            {
                'name': 'Swaraj Ashram',
                'category': 'heritage',
                'note': "Ashram commemorating the 1928 Bardoli Satyagraha farmers' movement; the town's most prominent tourist attraction",
            },
            {
                'name': 'Sardar Patel Museum',
                'category': 'museum',
                'note': 'Museum dedicated to Sardar Vallabhbhai Patel, displaying his personal belongings, photographs and documents',
            },
            {
                'name': 'Kedareshwar Temple',
                'category': 'religious',
                'note': '12th-century Shiva temple known for its ancient architecture and sacred pond',
            },
            {
                'name': 'Jalaram Temple',
                'category': 'religious',
                'note': 'Old, well-established temple in the heart of the city, popular with local devotees',
            },
            {
                'name': 'Swaminarayan Temple',
                'category': 'religious',
                'note': 'Modern white-marble temple of the Swaminarayan sect with intricate carvings',
            },
            {
                'name': 'Baben Lake',
                'category': 'nature',
                'note': "Lake with a small Sardar Patel statue at its centre, near Asia's largest sugar factory",
            },
            {
                'name': 'Shree Khedut Sahakari Khand Udyog Mandali',
                'category': 'attraction',
                'note': 'Reputed to be the largest sugar factory in Asia, crushing 11,000 tonnes of sugarcane daily; draws industrial-tourism visitors',
            },
        ],
        'food': [
            {
                'dish': 'Patra',
                'where': 'Jalaram Patra, local street-food stalls',
                'note': 'Steamed colocasia-leaf rolls; considered a famous street-food specialty of Bardoli',
            },
            {
                'dish': 'Ghughra',
                'where': 'local sweet shops',
                'note': 'Sweet stuffed fried pastry mentioned as a Bardoli food specialty',
            },
            {
                'dish': 'Khakhra Pizza',
                'where': 'local eateries',
                'note': 'A local fusion snack noted as a Bardoli specialty',
            },
            {
                'dish': 'Dabeli',
                'where': 'street food stalls',
                'note': 'Popular spiced potato snack served locally',
            },
            {
                'dish': 'Kachori',
                'where': 'street food stalls',
                'note': 'Popular fried savory snack available across the town',
            },
        ],
    },
    'borsad': {
        'character': "Borsad is a historic town in Anand District's fertile Charotar region, notable for its temples, a stepwell, and its role in the 1922-23 Borsad Satyagraha during India's independence movement.",
        'landmarks': [
            {
                'name': 'Swami Narayan Temple',
                'category': 'religious',
                'note': 'Prominent temple in Borsad, a notable pilgrimage stop',
            },
            {
                'name': 'Jalaram Temple',
                'category': 'religious',
                'note': 'Local temple dedicated to Jalaram Bapa',
            },
            {
                'name': 'Mahakaleshwar Mahadev Temple',
                'category': 'religious',
                'note': 'Shiva temple popular with local devotees',
            },
            {
                'name': 'Historic Stepwell',
                'category': 'heritage',
                'note': 'Old stepwell with notable architecture, a hidden historical attraction',
            },
            {
                'name': 'Borsad Satyagraha Sites',
                'category': 'heritage',
                'note': "Town holds historical importance from the 1922-23 Borsad Satyagraha, a key event in India's independence movement",
            },
        ],
        'food': [
            {
                'dish': 'Dhokla',
                'where': 'Local eateries in Borsad',
                'note': 'Classic Gujarati steamed snack recommended for an authentic experience',
            },
            {
                'dish': 'Khandvi',
                'where': 'Local eateries in Borsad',
                'note': 'Popular Gujarati rolled snack available in the town',
            },
            {
                'dish': 'Gujarati Thali',
                'where': 'Local restaurants',
                'note': 'Full traditional thali using fresh produce from the surrounding Charotar agricultural region',
            },
        ],
    },
    'ankleshwar': {
        'character': 'Ankleshwar is an industrial town on the Narmada river (twin town to Bharuch) with a cluster of notable Jain and Hindu temples nearby.',
        'landmarks': [
            {
                'name': 'Shri Ankleshwar Tirth',
                'category': 'religious',
                'note': 'Prominent Jain temple complex known for intricate carvings and sculptures showcasing Jain art and heritage.',
            },
            {
                'name': 'Chintamani Parsvanath Temple',
                'category': 'religious',
                'note': 'Famous for its towering statue of Bhagwan Chintamani Parsvanath.',
            },
            {
                'name': 'Swaminarayan Temple',
                'category': 'religious',
                'note': 'Temple dedicated to Lord Swaminarayan, offering a peaceful setting for prayer and meditation.',
            },
            {
                'name': 'Nilkanthdham Poicha',
                'category': 'religious',
                'note': 'Spiritual sanctuary on the banks of the Narmada with vibrant sculptures and an evening light show.',
            },
            {
                'name': 'Aadeshwar Mahadev Temple',
                'category': 'religious',
                'note': 'Small but atmospheric temple known for its serene ambiance and cultural celebrations.',
            },
            {
                'name': 'Golden Bridge (Narmada Bridge)',
                'category': 'heritage',
                'note': 'Iconic bridge over the Narmada connecting Ankleshwar to neighbouring Bharuch.',
            },
            {
                'name': 'Shoolpaneshwar Wildlife Sanctuary',
                'category': 'wildlife',
                'note': "Nearby sanctuary offering nature treks and safaris through the region's biodiversity.",
            },
            {
                'name': 'Ankleshwar Lake',
                'category': 'nature',
                'note': 'Green, quiet lakeside spot popular for relaxation and reflection.',
            },
        ],
        'food': [
            {
                'dish': 'Dhokla',
                'where': 'Local Gujarati eateries',
                'note': 'Steamed savoury cake, a Kathiawadi/Gujarati staple widely served in town.',
            },
            {
                'dish': 'Khandvi',
                'where': 'Local Gujarati eateries',
                'note': 'Rolled gram-flour snack, part of the popular Kathiawadi vegetarian spread here.',
            },
            {
                'dish': 'Kadhi',
                'where': 'Local Gujarati eateries',
                'note': 'Yogurt-based curry commonly paired with Gujarati thalis in Ankleshwar.',
            },
            {
                'dish': 'Bhel puri',
                'where': 'Street food stalls/roadside vendors',
                'note': 'Popular roadside chaat snack found across the town.',
            },
            {
                'dish': 'Momos',
                'where': 'Street food stalls/roadside vendors',
                'note': 'Common roadside street food alongside bhel puri and galgappe.',
            },
        ],
    },
    'botad': {
        'character': 'Botad is a small district town in Saurashtra, Gujarat, known mainly as a gateway to Salangpur temple and Velavadar wildlife sanctuary, with a Kathiyawadi food culture.',
        'landmarks': [
            {
                'name': 'Shree Kashtabhanjan Dev Hanumanji Mandir, Salangpur',
                'category': 'religious',
                'note': 'Famous Hanuman temple near Botad, one of the most visited in Gujarat, especially busy on Saturdays and Hanuman Jayanti',
            },
            {
                'name': 'Velavadar National Park (Blackbuck National Park)',
                'category': 'wildlife',
                'note': 'About 45 km from Botad, known for large blackbuck antelope populations and migratory bird sightings',
            },
            {
                'name': 'Ghogha Beach',
                'category': 'beach',
                'note': 'A relatively quiet beach around 40 km from Botad, away from crowded tourist spots',
            },
            {
                'name': 'Botad Fort',
                'category': 'heritage',
                'note': 'A historic fort dating back to the 15th century with notable architecture',
            },
            {
                'name': 'Botad Lake',
                'category': 'nature',
                'note': 'A local lake area popular for picnics and relaxing by the water',
            },
        ],
        'food': [
            {
                'dish': 'Dhokla',
                'where': 'Local eateries in Botad',
                'note': 'Steamed savory cake made from rice and chickpea flour, a Gujarati staple',
            },
            {
                'dish': 'Khandvi',
                'where': 'Local eateries in Botad',
                'note': 'Thin rolled gram-flour snack tempered with mustard seeds and curry leaves',
            },
            {
                'dish': 'Undhiyu',
                'where': 'Local eateries in Botad',
                'note': 'Popular mixed-vegetable Gujarati dish, typically prepared in winter',
            },
            {
                'dish': 'Tomato and sev',
                'where': 'Local Kathiyawadi home-style eateries',
                'note': 'Tangy tomato curry mixed with crunchy sev, a classic Kathiyawadi dish',
            },
            {
                'dish': 'Mohanthal and Basundi',
                'where': 'Local sweet shops',
                'note': 'Mohanthal is a gram-flour fudge with nuts and cardamom; Basundi is a chilled creamy milk dessert',
            },
            {
                'dish': 'Rotla with shaak and buttermilk',
                'where': 'Everyday home-style meals in Botad',
                'note': 'Staple farmer-based daily meal in the region combining millet flatbread, vegetable curry, and buttermilk',
            },
        ],
    },
    'dabhoi': {
        'character': 'Dabhoi is a historic fortified market town in Vadodara district, best known for its 13th-century Solanki/Vaghela-era stone fort with elaborately carved gates.',
        'landmarks': [
            {
                'name': 'Dabhoi Fort',
                'category': 'heritage',
                'note': '13th-century fort built in 1231 CE under Vaghela ruler Viradhavala, one of the finest examples of Maru-Gurjara (Solanki) military architecture.',
            },
            {
                'name': 'Hira Bhagol (Hira Gate)',
                'category': 'heritage',
                'note': "The fort's most celebrated gate, intricately carved and known as the 'Gate of Diamonds'.",
            },
            {
                'name': 'Vadodari Bhagol (Baroda Gate)',
                'category': 'heritage',
                'note': 'A formidable two-storied sandstone gate reflecting the peak of Solanki-era stone architecture.',
            },
            {
                'name': 'Champaner Gate and Nandod (Nand) Gate',
                'category': 'heritage',
                'note': "Two of the fort's other main gates, along with Hira and Vadodari Bhagol, forming the fort's four historic entrances.",
            },
            {
                'name': 'Shri Lodhan Parshwanath Jain Temple',
                'category': 'religious',
                'note': 'Ancient Jain temple known for a sand idol said to have remained undamaged after long submersion in water.',
            },
        ],
        'food': [
            {
                'dish': 'Bhajiya (fried snacks)',
                'where': 'Valji Bhajiya Shop, Dabhoi',
                'note': 'A locally well-known bhajiya stall frequently cited as a Dabhoi food landmark.',
            },
            {
                'dish': 'Kathiyawadi thali',
                'where': 'Local Kathiyawadi restaurants around town',
                'note': "Popular regional Gujarati cuisine widely served in Dabhoi's eateries.",
            },
        ],
    },
    'deesa': {
        'character': 'Deesa is a modest trading town in Banaskantha district known more for its everyday temples and heritage markers than for major tourism draws.',
        'landmarks': [
            {
                'name': 'Hawai Pillar',
                'category': 'heritage',
                'note': 'British-era tower built in 1824 to measure air pressure, renovated as a heritage monument in 2013',
            },
            {
                'name': 'Swaminarayan Temple',
                'category': 'religious',
                'note': 'local Swaminarayan temple in Deesa',
            },
            {
                'name': 'Jalaram Temple',
                'category': 'religious',
                'note': 'temple dedicated to Jalaram Bapa',
            },
            {
                'name': 'Soneshwar Mahadev Temple',
                'category': 'religious',
                'note': 'Shiva temple in Deesa',
            },
            {
                'name': 'Vishveshvar Mahadev Temple',
                'category': 'religious',
                'note': 'Shiva temple in Deesa',
            },
            {
                'name': 'Balaram Palace',
                'category': 'heritage',
                'note': 'heritage hotel/palace near Deesa, also a spot for nature walks and birdwatching',
            },
        ],
        'food': [
            {
                'dish': 'Dhokla',
                'where': 'local eateries/markets',
                'note': 'savory steamed rice-and-chickpea flour snack, popular breakfast item',
            },
            {
                'dish': 'Khandvi',
                'where': 'local eateries/markets',
                'note': 'gram flour rolls topped with mustard seeds and sesame',
            },
            {
                'dish': 'Thepla',
                'where': 'local eateries/markets',
                'note': 'spiced flatbread, common Gujarati staple food in the area',
            },
        ],
    },
    'dholka': {
        'character': 'Dholka is a historic small town near Ahmedabad known for its fort, Sufi shrine, and traditional Gujarati market life.',
        'landmarks': [
            {
                'name': 'Dholka Fort',
                'category': 'heritage',
                'note': 'Historic fort offering views of the surrounding countryside',
            },
            {
                'name': 'Hazrat Shah Alam Dargah',
                'category': 'religious',
                'note': 'Revered Sufi shrine that draws pilgrims across Gujarat, especially during its annual Urs festival',
            },
            {
                'name': 'Local markets',
                'category': 'shopping',
                'note': 'Bustling traditional markets selling handicrafts, textiles, and jewelry',
            },
        ],
        'food': [
            {
                'dish': 'Dhokla',
                'where': 'Local eateries and markets',
                'note': 'Steamed fermented batter of rice and chickpea flour, served with green chutney',
            },
            {
                'dish': 'Thepla',
                'where': 'Local eateries',
                'note': 'Spicy flatbread made with fenugreek leaves, yogurt, and spices',
            },
            {
                'dish': 'Gujarati Thali',
                'where': 'Local restaurants',
                'note': 'Traditional thali with seasonal vegetables, dal, and flatbread',
            },
        ],
    },
    'dehgam': {
        'character': 'A small town in Gandhinagar district, Gujarat, known for its temples and the nearby Zanzari Waterfalls on the Vatrak River rather than large-scale tourism infrastructure.',
        'landmarks': [
            {
                'name': 'Hanumanji Temple',
                'category': 'religious',
                'note': 'A revered temple in Dehgam dedicated to Lord Hanuman.',
            },
            {
                'name': 'Dholeshwar Mahadev Temple',
                'category': 'religious',
                'note': "An ancient Shiva temple noted as part of Gujarat's spiritual heritage in and around Dehgam.",
            },
            {
                'name': 'Zanzari Waterfalls',
                'category': 'nature',
                'note': 'A 25-foot waterfall on the Vatrak River near Dehgam, landing on rocks and boulders, about 80 km from Ahmedabad.',
            },
            {
                'name': 'Nal Sarovar Bird Sanctuary',
                'category': 'wildlife',
                'note': 'A bird sanctuary a short drive from Dehgam, popular for birdwatching.',
            },
            {
                'name': 'Adalaj Stepwell',
                'category': 'heritage',
                'note': 'A well-known ornate stepwell a short drive from Dehgam, an example of ancient stepwell architecture.',
            },
        ],
        'food': [
            {
                'dish': 'Dhokla',
                'where': 'Local Gujarati eateries in Dehgam',
                'note': 'Popular steamed savory Gujarati snack found widely across the region.',
            },
            {
                'dish': 'Thepla',
                'where': 'Local Gujarati eateries in Dehgam',
                'note': 'Common Gujarati flatbread, a regional staple.',
            },
            {
                'dish': 'Fafda',
                'where': 'Local Gujarati eateries in Dehgam',
                'note': 'Crispy gram-flour snack typical of Gujarati cuisine.',
            },
            {
                'dish': 'Khandvi',
                'where': 'Local Gujarati eateries in Dehgam',
                'note': "Rolled gram-flour snack common in the region's food culture.",
            },
        ],
    },
    'devgadh_baria': {
        'character': 'A princely-state town in Dahod district, Gujarat, built around royal palaces and forts on the banks of the Panam River, once called "The Paris of Gujarat" for its planned avenues and gardens.',
        'landmarks': [
            {
                'name': 'Devgadh Baria Fort / Darbargadh',
                'category': 'heritage',
                'note': 'Royal residence founded by Raja Dungarsinghji around 1524, built in a mix of traditional and European architectural styles.',
            },
            {
                'name': 'Rajmahal Palace',
                'category': 'heritage',
                'note': 'Neo-classical palace, still home to the Chauhan royal family, built during the reformist era of Maharawal Ranjitsinhji.',
            },
            {
                'name': 'Shri Swaminarayan Mandir',
                'category': 'religious',
                'note': "One of the town's well-known temples.",
            },
            {
                'name': 'Shri Ranchhodraiji Maharaj Mandir',
                'category': 'religious',
                'note': 'Notable ancient temple in the town.',
            },
            {
                'name': 'Chougania Valley',
                'category': 'nature',
                'note': 'Scenic valley near the Tidki reservoir known for sunset views and storks flying home at twilight.',
            },
            {
                'name': 'Khos Valley',
                'category': 'nature',
                'note': 'A quieter valley about 2 km from Chougania with hiking trails away from traffic.',
            },
            {
                'name': 'Ratanmahal Sloth Bear Sanctuary',
                'category': 'wildlife',
                'note': 'Mixed deciduous forest sanctuary known for its population of sloth bears.',
            },
        ],
        'food': [
            {
                'dish': 'Makka (corn) preparations',
                'where': 'Local eateries',
                'note': 'Corn is an agricultural staple of the region and features widely in local dishes.',
            },
            {
                'dish': 'Fried freshwater fish',
                'where': 'Small local eateries near the lake',
                'note': 'Fish from the nearby lake, lightly marinated and fried, reflecting a blend of Rajasthani and Gujarati flavours.',
            },
            {
                'dish': 'Lassi',
                'where': 'Local eateries in town',
                'note': 'A must-try refreshing local specialty.',
            },
            {
                'dish': 'Kulfi',
                'where': 'Local eateries in town',
                'note': 'Traditional Indian frozen dessert popular in Baria.',
            },
        ],
    },
    'gondal': {
        'character': 'Gondal is a small former princely-state town in Rajkot district, Gujarat, known for its royal palaces, vintage car collection, and riverside heritage architecture.',
        'landmarks': [
            {
                'name': 'Naulakha Palace',
                'category': 'heritage',
                'note': 'Oldest palace in Gondal, noted for its architecture, antique collections, and the Royal Vintage Car Garage',
            },
            {
                'name': 'Riverside Palace',
                'category': 'heritage',
                'note': 'Heritage hotel with lush gardens and views over the Gondali river',
            },
            {
                'name': 'Orchard Palace',
                'category': 'heritage',
                'note': 'Heritage stay set amid tranquil gardens, part of the former royal estate',
            },
            {
                'name': 'Shri Swaminarayan Mandir',
                'category': 'religious',
                'note': 'Grand spiritual site known for its ornate architecture',
            },
            {
                'name': 'Bhuvaneshwari Temple',
                'category': 'religious',
                'note': 'Revered local shrine in Gondal',
            },
        ],
        'food': [
            {
                'dish': 'Dhokla',
                'where': 'Local eateries and street stalls',
                'note': 'Steamed snack of fermented rice and chickpea flour, fluffy and tangy, served with green chutney',
            },
            {
                'dish': 'Khandvi',
                'where': 'Local eateries',
                'note': 'Rolled gram-flour and yogurt snack tempered with mustard seeds, garnished with coconut and coriander',
            },
            {
                'dish': 'Undhiyu',
                'where': 'Local eateries, especially in winter',
                'note': 'Traditional mixed vegetable dish with a blend of spices, popular in the winter months',
            },
            {
                'dish': 'Fafda',
                'where': 'Street food stalls',
                'note': 'Crispy gram-flour snack often served with spicy chutney as a tea-time bite',
            },
            {
                'dish': 'Fafda-jalebi',
                'where': 'Local street food destinations',
                'note': 'Classic Gujarati street food combo of savory fafda and sweet jalebi',
            },
            {
                'dish': 'Dabeli',
                'where': 'Street food stalls',
                'note': 'Popular local street snack offering authentic Kathiawadi flavor',
            },
        ],
    },
    'godhra': {
        'character': 'Godhra is a small district-headquarters town in Panchmahal, Gujarat, known more as a convenient base for nearby heritage sites than a major tourist destination itself.',
        'landmarks': [
            {
                'name': 'Ranchhodji Temple',
                'category': 'religious',
                'note': "18th-century temple dedicated to Lord Krishna's Ranchhodji form, noted for intricate wooden carvings, colorful paintings and ornate pillars; one of Godhra's most revered sites.",
            },
            {
                'name': 'Khodiyar Mandir',
                'category': 'religious',
                'note': 'Popular pilgrimage temple to Goddess Khodiyar located about 20 km from Godhra, visited by thousands of devotees.',
            },
            {
                'name': 'Champaner-Pavagadh Archaeological Park',
                'category': 'heritage',
                'note': 'UNESCO World Heritage Site near Godhra with historical ruins; visitors climb Pavagadh hill to reach the Kalika Mata Temple and enjoy panoramic views.',
            },
        ],
        'food': [
            {
                'dish': 'Makai No Rotlo (Maize Bread)',
                'where': 'Local eateries in Godhra',
                'note': 'Considered the famous signature food of Godhra.',
            },
            {
                'dish': 'Fafda-Jalebi',
                'where': 'Local breakfast stalls',
                'note': 'Traditional Gujarati breakfast combo of crispy gram-flour fafda and sweet syrupy jalebi, often with masala chai.',
            },
            {
                'dish': 'Sev Usal',
                'where': 'Godhra street food stalls',
                'note': 'Godhra specialty: a spicy curry topped with crispy sev, served with pav bread.',
            },
            {
                'dish': 'Gujarati Thali',
                'where': 'General area restaurants',
                'note': 'Elaborate platter with dal, kadhi, shaak, rotli, rice, pickles and sweets, including regional items like dhokla, thepla and undhiyu.',
            },
        ],
    },
    'dahod': {
        'character': 'Dahod is a tribal-heartland district in eastern Gujarat known for hills, forests, and lakes, historically noted as the birthplace of Emperor Aurangzeb.',
        'landmarks': [
            {
                'name': 'Mangadh Hill',
                'category': 'religious',
                'note': 'A sacred site of faith for tribal communities of Gujarat, Rajasthan and Madhya Pradesh associated with Guru Govindsinh.',
            },
            {
                'name': 'Panchkrishna Temple',
                'category': 'heritage',
                'note': 'A 12th-century historical temple located at Therka village in Zalod taluka.',
            },
            {
                'name': 'Bhavka Shiva Temple',
                'category': 'religious',
                'note': 'A 10th-century Shiva temple set in the interiors of Dahod district.',
            },
            {
                'name': 'Ratanpur Bear Sanctuary',
                'category': 'wildlife',
                'note': "A sloth bear sanctuary located in Dhanpur taluka, part of Dahod's eco-tourism appeal.",
            },
            {
                'name': 'Chhab Lake',
                'category': 'nature',
                'note': 'A lake at Dahod district headquarters, popular for scenic strolls.',
            },
            {
                'name': 'Gadi Fort',
                'category': 'heritage',
                'note': 'A historic fort located in the center of Dahod city.',
            },
        ],
        'food': [
            {
                'dish': 'Sohan Papdi',
                'where': 'local sweet shops',
                'note': 'A popular flaky sweet commonly made and sold in Dahod.',
            },
            {
                'dish': 'Kachori',
                'where': 'Ratlami Sev Bhandar',
                'note': 'A hotspot for snacks, well known across Gujarat for its kachori, along with samosa and ratlami sev.',
            },
            {
                'dish': 'Fafda-Jalebi',
                'where': 'local breakfast stalls',
                'note': 'Classic Gujarati breakfast combo of crispy gram-flour fritters with sweet syrupy jalebi, popular in the area.',
            },
            {
                'dish': 'Dal-Bati',
                'where': 'local tribal (Adivasi) eateries',
                'note': 'Traditional Adivasi dish of lentils with wheat-flour bread, often soaked in ghee, served at tribal eateries and cultural events.',
            },
            {
                'dish': 'Mattha and Pakwan',
                'where': 'local sweet/snack shops',
                'note': "Traditional sweet items associated with Dahod's local food culture.",
            },
        ],
    },
    'dhrangadhra': {
        'character': 'Dhrangadhra is a small former princely-state town in Surendranagar district, Gujarat, known for its royal palace heritage and proximity to the Little Rann of Kutch.',
        'landmarks': [
            {
                'name': 'Dhrangadhra Palace',
                'category': 'heritage',
                'note': 'Late 19th-century Indo-Saracenic royal residence of the Maharaja of Dhrangadhra, blending Gothic, Mughal, and Rajput architectural styles.',
            },
            {
                'name': 'Khodiyar Mandir',
                'category': 'religious',
                'note': 'Revered Hindu temple dedicated to goddess Khodiyar, drawing devotees and tourists.',
            },
            {
                'name': 'Swaminarayan Temple',
                'category': 'religious',
                'note': 'Notable temple dedicated to Lord Swaminarayan with striking architecture.',
            },
            {
                'name': 'Bavani Lake',
                'category': 'nature',
                'note': 'Recreational lake about 8 km from town, popular for boating and picnics amid greenery.',
            },
            {
                'name': 'Little Rann of Kutch',
                'category': 'wildlife',
                'note': 'Vast salt marsh wildlife sanctuary roughly 50 km away, home to the endangered Asiatic wild ass.',
            },
            {
                'name': 'Shitla Mata Temple',
                'category': 'religious',
                'note': "Local temple mentioned among the town's notable religious sites.",
            },
        ],
        'food': [],
    },
    'dhrol': {
        'character': 'A small former princely-state town in Jamnagar district, Gujarat, known for its Jadeja-dynasty history and the nearby Bhucharmori battlefield, with thin modern tourism coverage.',
        'landmarks': [
            {
                'name': 'Dhrol Fort / Darbargadh',
                'category': 'heritage',
                'note': 'Historic fort/palace complex of the Jadeja rulers of the former Dhrol princely state (founded 1539).',
            },
            {
                'name': 'Bhucharmori battlefield',
                'category': 'heritage',
                'note': "Nearby historic battle site where Jam Sataji's forces fought Akbar's army commander Mirza Aziz Koka.",
            },
        ],
        'food': [
            {
                'dish': 'Gujarati vegetarian thali',
                'where': 'local eateries in Dhrol town',
                'note': 'Search results note the local cuisine follows general Gujarati culinary tradition, sweet-and-savory vegetarian fare, with no dish specifically singled out for Dhrol.',
            },
        ],
    },
    'gandhidham': {
        'character': 'Gandhidham is a planned port town in Kutch, Gujarat, known for Kandla Port, Jain pilgrimage sites, and a strong Sindhi-refugee cultural imprint blended with Kutchi tradition.',
        'landmarks': [
            {
                'name': 'Bhadreswar Jain Temple',
                'category': 'religious',
                'note': 'Centuries-old Jain pilgrimage temple, among the oldest Jain temples in India',
            },
            {
                'name': 'Chandra Prabh Dham Teerth',
                'category': 'religious',
                'note': 'Well-known Jain pilgrimage site located on NH-8A',
            },
            {
                'name': 'Purneshwar Temple',
                'category': 'heritage',
                'note': 'Built between the 9th and 10th centuries, noted for its ancient sculptures and architecture',
            },
            {
                'name': 'Gandhi Samadhi',
                'category': 'heritage',
                'note': 'Memorial in the heart of Gandhidham dedicated to Mahatma Gandhi',
            },
            {
                'name': 'Bhujiyo Hill',
                'category': 'nature',
                'note': 'Scenic hill near Gandhidham offering panoramic views with an easy trek',
            },
            {
                'name': 'Kandla Port',
                'category': 'attraction',
                'note': "One of India's biggest sea ports, built in 1957, near Gandhidham",
            },
            {
                'name': 'Gandhidham Cultural Centre and Museum',
                'category': 'museum',
                'note': 'Showcases Kutchi and Gujarati artifacts, traditional crafts, clothing and jewellery',
            },
            {
                'name': 'Mandvi Beach',
                'category': 'beach',
                'note': 'Short drive from Gandhidham, known for clean sands and watersports like parasailing and jet-skiing',
            },
        ],
        'food': [
            {
                'dish': 'Dabeli',
                'where': 'local street food stalls',
                'note': 'Kutch-origin sweet-savoury snack: potato-stuffed bun with peanut and tamarind chutneys',
            },
            {
                'dish': 'Bajara na rotla with besan curry',
                'where': 'local Kutchi eateries',
                'note': 'Pearl millet flatbread served with spicy chickpea flour curry, a signature Gandhidham dish',
            },
            {
                'dish': 'Sai Bhaji',
                'where': 'Sindhi restaurants in town',
                'note': "Popular Sindhi spinach-based curry, reflecting the city's large Sindhi settler community",
            },
            {
                'dish': 'Pallo Machhi / Machhi Palli',
                'where': 'Sindhi eateries',
                'note': 'Traditional Sindhi fish delicacies, one using hilsa fish, the other fish cooked with chickpea leaves',
            },
            {
                'dish': 'Gujarati snacks (dhokla, thepla, fafda, handvo)',
                'where': 'local snack shops',
                'note': "Common Gujarati specialties widely available reflecting the region's food habits",
            },
            {
                'dish': 'Kutchi milk sweets',
                'where': 'sweet shops around town',
                'note': 'Kutch region is noted for its milk-based sweets',
            },
        ],
    },
    'himatnagar': {
        'character': 'Himatnagar is a district-headquarters town on the Hathmathi river in Sabarkantha, Gujarat, known for its temples, historic wells/fort remains, and textile-handicraft trade, with easy access to nearby Idar and Shamlaji.',
        'landmarks': [
            {
                'name': 'Bholeshwar Temple',
                'category': 'religious',
                'note': 'Shiva temple on the banks of the Hathmathi river, draws large crowds during Shivratri',
            },
            {
                'name': 'Mahavir Swami Jain Temple',
                'category': 'religious',
                'note': 'Ancient Jain temple known for its intricate carvings and architecture',
            },
            {
                'name': 'Himmat Singh Fort',
                'category': 'heritage',
                'note': 'Historic fort remains on the banks of the Hathmathi river',
            },
            {
                'name': 'Kazi ni Vavdi',
                'category': 'heritage',
                'note': 'Famous old stepwell with inscriptions recording when and by whom it was built',
            },
            {
                'name': 'Nagarpalika Garden',
                'category': 'garden',
                'note': 'Quiet green park about 2 km from the city centre',
            },
            {
                'name': 'Tirupati Rushivan Adventure Park',
                'category': 'attraction',
                'note': 'Adventure park with thrill rides for children and families',
            },
        ],
        'food': [
            {
                'dish': 'Daal-Baati',
                'where': 'Local eateries in Himatnagar',
                'note': 'Cited as a uniquely tasting local specialty of the town',
            },
            {
                'dish': 'Panipuri',
                'where': 'Street food stalls around town',
                'note': 'Noted as a distinctive local favorite',
            },
            {
                'dish': 'Khaman Dhokla',
                'where': 'Local snack shops',
                'note': 'Steamed fermented gram-flour snack tempered with mustard seeds and curry leaves',
            },
            {
                'dish': 'Gujarati Thali',
                'where': 'General area / local restaurants',
                'note': 'Traditional meal of dal, rice/bhat, rotli and shaak with pickles and papad',
            },
        ],
    },
    'jasdan': {
        'character': 'Jasdan is a small taluka town amid the Mandav Hills in Rajkot district, known for temples, diamond polishing craft, and groundnut/cotton farming.',
        'landmarks': [
            {
                'name': 'Hingolgadh Nature Education Sanctuary (Hingolgadh Palace)',
                'category': 'wildlife',
                'note': 'A wildlife sanctuary built around a former royal palace on the Jasdan-Ahmedabad highway, popular for nature walks.',
            },
            {
                'name': 'Jalaram Temple, Hirpara',
                'category': 'religious',
                'note': 'Well-known Jalaram devotional temple near Jasdan.',
            },
            {
                'name': 'Bhutada Dada Temple',
                'category': 'religious',
                'note': 'Local religious shrine frequented by visitors to Jasdan.',
            },
            {
                'name': 'Ram Mandir',
                'category': 'religious',
                'note': 'A prominent temple in Jasdan town.',
            },
            {
                'name': 'Shree Batuk Hanumanji Temple',
                'category': 'religious',
                'note': 'One of the notable temples in the Jasdan area.',
            },
            {
                'name': 'Shree Gupteshwar Mahadev Temple',
                'category': 'religious',
                'note': "Shiva temple listed among Jasdan's religious sites.",
            },
            {
                'name': 'Bileshwar Mahadev Sanctuary',
                'category': 'religious',
                'note': 'Temple/sanctuary site near Jasdan.',
            },
        ],
        'food': [
            {
                'dish': 'Local Kathiawadi/ethnic Gujarati fare',
                'where': 'Restaurants and dhabas around town',
                'note': 'Jasdan is noted for serving traditional ethnic Kathiawadi-style food to visitors.',
            },
            {
                'dish': 'Multi-cuisine restaurant meals',
                'where': 'Aaditya Garden Restaurant, Anand Nagar',
                'note': 'One of the prominent dining spots called out for foodies visiting Jasdan.',
            },
        ],
    },
    'halvad': {
        'character': 'Halvad is a small ancient fortified town in Morbi district on the southern edge of the Little Rann of Kutch, known for its Rajput-era wooden palace and temples on the Samatsar lake.',
        'landmarks': [
            {
                'name': 'Halvad Fort',
                'category': 'heritage',
                'note': "Ancient fort with old walls and towers offering panoramic views of the surrounding landscape, testament to Halvad's strategic past.",
            },
            {
                'name': 'Ek Dandiya Mahal (wooden Royal Palace)',
                'category': 'heritage',
                'note': 'On the banks of Samatsar lake, considered the finest example of wooden Rajput palace architecture in Gujarat, with a zenana, public audience hall, temple, and pleasure garden.',
            },
            {
                'name': 'Swaminarayan Temple',
                'category': 'religious',
                'note': 'Temple dedicated to Lord Swaminarayan, noted for intricate carvings and a serene atmosphere.',
            },
            {
                'name': 'Jain Temples at Tikar',
                'category': 'religious',
                'note': 'Group of Jain temples believed to have been constructed in 1837.',
            },
            {
                'name': 'Bhavani Bhuteshwar Mahadev Temple',
                'category': 'religious',
                'note': 'A roughly 500-year-old Shiva temple in Halvad.',
            },
            {
                'name': 'Samatsar Talav',
                'category': 'nature',
                'note': "Lake beside the old royal palace, central to Halvad's historic townscape.",
            },
            {
                'name': 'Gangajaliya Lake',
                'category': 'nature',
                'note': 'A serene lake popular as a local spot for recreation and relaxation.',
            },
            {
                'name': 'Bhogavo River',
                'category': 'nature',
                'note': 'Scenic riverbanks with greenery, popular for picnics and leisurely strolls.',
            },
        ],
        'food': [],
    },
    'jetpur': {
        'character': "Jetpur is a Rajkot-district textile town in Gujarat, renowned as one of India's largest hubs for cotton saree printing, block printing, and screen printing.",
        'landmarks': [
            {
                'name': 'Jetpur Textile Market',
                'category': 'shopping',
                'note': 'Famous market for colorful printed cotton sarees, khanga/kitange fabrics, block printing and bandhani work',
            },
            {
                'name': 'Jetpur City Palace',
                'category': 'heritage',
                'note': 'Old palace with impressive traditional architecture',
            },
            {
                'name': 'Jetpur Fort',
                'category': 'heritage',
                'note': "Historic fort offering a glimpse into the area's past",
            },
            {
                'name': 'Nilkantheshwar Temple',
                'category': 'religious',
                'note': 'Peaceful temple known for beautiful views',
            },
            {
                'name': 'Kankai Mata Temple',
                'category': 'religious',
                'note': 'Local temple area popular for relaxing and unwinding',
            },
            {
                'name': 'Talaja Lake',
                'category': 'nature',
                'note': 'Lake spot in the area ideal for a peaceful outing',
            },
        ],
        'food': [],
    },
    'kapadvanj': {
        'character': "A small historic town in Gujarat's Kheda district known for its Jain temple architecture and traditional Gujarati vegetarian cuisine.",
        'landmarks': [
            {
                'name': 'Jain Temple (dedicated to Lord Mahavir)',
                'category': 'religious',
                'note': 'Historic Jain temple noted for intricate architecture and artistry',
            },
            {
                'name': 'Bhadrakali Temple',
                'category': 'religious',
                'note': 'Known for its beautiful carvings and serene ambiance',
            },
            {
                'name': 'Ancient step wells and forts (surrounding countryside)',
                'category': 'heritage',
                'note': "Remnants of the region's historical past dotting the area around town",
            },
            {
                'name': 'Kanjari village (nearby)',
                'category': 'attraction',
                'note': 'Small village known for traditional crafts and vibrant local rural culture',
            },
        ],
        'food': [
            {
                'dish': 'Dhokla',
                'where': 'local eateries/thali restaurants',
                'note': 'Popular Gujarati steamed snack commonly served in the area',
            },
            {
                'dish': 'Khandvi',
                'where': 'local eateries/thali restaurants',
                'note': 'Traditional Gujarati rolled snack',
            },
            {
                'dish': 'Gujarati Thali',
                'where': 'local restaurants',
                'note': 'Platter of assorted vegetarian dishes, lentils, and breads like thepla',
            },
            {
                'dish': 'Fafda and Jalebi',
                'where': 'street food stalls',
                'note': 'Common street-food snack combination',
            },
            {
                'dish': 'Samosas',
                'where': 'street food stalls',
                'note': 'Popular fried snack sold at local stalls',
            },
        ],
    },
    'idar': {
        'character': 'A small historic hill-fort town in Sabarkantha district, Gujarat, blending Rajasthani and Gujarati influences, known for its ancient Idar Fort and Jain temples.',
        'landmarks': [
            {
                'name': 'Idar Fort (Idariyo Gadh)',
                'category': 'heritage',
                'note': '12th-century hilltop fort with old palaces, temples, water reservoirs, massive gates, and hundreds of resident peacocks',
            },
            {
                'name': "Ruthi Rani no Mahal (Angry Queen's Palace)",
                'category': 'heritage',
                'note': 'Palace within the fort complex offering mesmerizing views of the surrounding landscape',
            },
            {
                'name': 'Zarneshwar Mahadev Temple',
                'category': 'religious',
                'note': 'Cave temple within the fort featuring a natural waterfall',
            },
            {
                'name': 'Shantinath Shwetambar Jain Dersar',
                'category': 'religious',
                'note': 'Historic Jain temple on Idar Gadh built around 1750, with architecturally marvelous carvings and a chief idol believed to date to the Maurya Era',
            },
            {
                'name': 'Digambar Jain Temple',
                'category': 'religious',
                'note': "Jain temple constructed around 1805, part of Idar's cluster of ancient Jain shrines",
            },
            {
                'name': 'Polo Forest',
                'category': 'nature',
                'note': 'Dense forested area about 35 km from Idar, popular for trekking and nature exploration',
            },
            {
                'name': 'Shamlaji Temple',
                'category': 'religious',
                'note': 'Popular Hindu temple dedicated to Lord Vishnu on the banks of the Meshwo River, about 47 km from Idar Fort',
            },
        ],
        'food': [
            {
                'dish': 'Dhokla',
                'where': 'Local eateries around town',
                'note': 'Savory steamed cake made from fermented rice and chickpea batter',
            },
            {
                'dish': 'Khaman',
                'where': 'Local eateries around town',
                'note': 'Spiced chickpea flour snack, a staple local dish',
            },
            {
                'dish': 'Fafda-Jalebi',
                'where': 'Street stalls',
                'note': 'Popular street food combo enjoyed in the early morning or as an evening snack',
            },
            {
                'dish': 'Dal Bati Churma',
                'where': 'Local restaurants',
                'note': "Rajasthani dish common in Idar due to the town's proximity to Rajasthan and mixed culinary influences",
            },
            {
                'dish': 'Gujarati Thali',
                'where': 'Small eateries and roadside stalls',
                'note': 'Full-platter meal of assorted Gujarati dishes using fresh vegetables, lentils, and spices',
            },
        ],
    },
    'kadi': {
        'character': 'Kadi is a small temple town in Mehsana district, Gujarat, known for its Hanuman shrine, local lake bird sanctuary nearby, and modest heritage sites rather than major tourism infrastructure.',
        'landmarks': [
            {
                'name': 'Kadi Sarangpur Hanuman Temple',
                'category': 'religious',
                'note': 'Renowned temple dedicated to Lord Hanuman that draws many devotees',
            },
            {
                'name': 'Umiya Mata Temple',
                'category': 'religious',
                'note': 'Local temple dedicated to Umiya Mata, a popular Gujarati deity',
            },
            {
                'name': 'Oghadnath Mahadev Temple',
                'category': 'religious',
                'note': "Shiva temple noted among Kadi's tourist points",
            },
            {
                'name': 'Malhavrav Fort',
                'category': 'heritage',
                'note': "Historical fort listed among Kadi's tourist points",
            },
            {
                'name': 'Malji Bhagat ni Vav',
                'category': 'heritage',
                'note': 'A traditional stepwell (vav) in the town',
            },
            {
                'name': 'Dasiya Pir Dargah',
                'category': 'religious',
                'note': 'Local dargah/shrine noted as a tourist point',
            },
            {
                'name': 'Municipal Garden',
                'category': 'garden',
                'note': 'Local public garden in Kadi',
            },
            {
                'name': 'Thol Bird Sanctuary',
                'category': 'wildlife',
                'note': 'Bird sanctuary about 22 km from Kadi, home to roughly 150 bird species including migratory waterbirds',
            },
        ],
        'food': [
            {
                'dish': 'Gujarati Thali',
                'where': 'Local eateries in Kadi',
                'note': 'Classic multi-dish Gujarati thali is a must-try for visitors',
            },
            {
                'dish': 'Dhokla',
                'where': 'Local markets/eateries',
                'note': 'Popular steamed savory cake, a Gujarati staple served widely in the region',
            },
            {
                'dish': 'Khandvi',
                'where': 'Local markets/eateries',
                'note': 'Rolled gram-flour snack commonly served as part of Gujarati cuisine here',
            },
        ],
    },
    'karjan': {
        'character': 'A small town in Vadodara district best known for its dam on the Karjan River and its saree market rather than major tourist draws.',
        'landmarks': [
            {
                'name': 'Karjan Dam',
                'category': 'nature',
                'note': 'Scenic dam and reservoir on the Karjan River, popular for boating and fishing',
            },
            {
                'name': 'Gopinath Mahadev Temple',
                'category': 'religious',
                'note': 'Historical temple landmark in Karjan',
            },
            {
                'name': 'Shri Shankheshwar Parshwanath Jain Tirth (Anastu)',
                'category': 'religious',
                'note': 'Notable Jain pilgrimage temple near Karjan',
            },
            {
                'name': 'Sumeru Navkar Jain Tirth (Golden Temple)',
                'category': 'religious',
                'note': 'Jain temple known locally as the Golden Temple',
            },
            {
                'name': 'Karjan Saree Market',
                'category': 'shopping',
                'note': 'Local market known for sarees, draws shoppers from surrounding areas',
            },
        ],
        'food': [
            {
                'dish': 'Samosa',
                'where': 'Local snack stalls, Karjan',
                'note': 'Popular local snack item',
            },
            {
                'dish': 'Khaman',
                'where': 'Local snack stalls, Karjan',
                'note': 'Steamed gram-flour snack, a regional Gujarati favorite',
            },
            {
                'dish': 'Sev-Khamni',
                'where': 'Local snack stalls, Karjan',
                'note': 'Khaman topped with crunchy sev, a well-known Karjan snack',
            },
            {
                'dish': 'Gota',
                'where': 'Local snack stalls, Karjan',
                'note': 'Deep-fried gram-flour fritters, common local snack',
            },
        ],
    },
    'kalavad': {
        'character': 'Kalavad is a small, historic temple town in Jamnagar district, Gujarat, known for its religious sites and quiet small-town character rather than major tourism infrastructure.',
        'landmarks': [
            {
                'name': 'Shitla Mataji Temple',
                'category': 'religious',
                'note': 'Considered the primary and most notable attraction in Kalavad, a well-known local pilgrimage temple.',
            },
            {
                'name': 'Nava Ranuja Temple',
                'category': 'religious',
                'note': 'Located near Jasapur village close to Kalavad, visited for its peaceful setting and religious significance.',
            },
            {
                'name': 'Local handicraft and textile markets',
                'category': 'shopping',
                'note': 'Kalavad is known for local craftsmanship, with markets offering regional handicrafts and textiles.',
            },
        ],
        'food': [
            {
                'dish': 'Dhokla',
                'where': 'Local eateries, Jamnagar district',
                'note': 'Popular steamed savory cake from fermented rice and chickpea flour, a Gujarat-region staple found around Jamnagar district including Kalavad.',
            },
            {
                'dish': 'Dal Dhokli',
                'where': 'Local eateries, Jamnagar district',
                'note': 'Traditional Gujarati dish combining lentil soup with wheat flour dumplings, common across the wider Jamnagar region.',
            },
        ],
    },
    'kalol': {
        'character': 'Kalol is a small, quiet Gujarat town valued as a base for temple visits and day trips to nearby UNESCO heritage sites rather than for its own big attractions.',
        'landmarks': [
            {
                'name': 'Ambaji Temple',
                'category': 'religious',
                'note': 'One of the well-known temples in Kalol city, a local pilgrimage spot',
            },
            {
                'name': 'Kapileshwar Mahadev Temple',
                'category': 'religious',
                'note': 'Notable Shiva temple in Kalol',
            },
            {
                'name': 'Bharat Sevashram (Durga, Hanuman & Ganesh temples)',
                'category': 'religious',
                'note': 'Temple complex housing multiple deities within one campus',
            },
            {
                'name': 'Jamiyatpura Hanumanji Temple',
                'category': 'religious',
                'note': 'Hanuman temple about 10 km from Kalol in Jamiyatpura village',
            },
            {
                'name': 'Adalaj Stepwell',
                'category': 'heritage',
                'note': '15th-century stepwell built by Queen Rudabai, ~10 km away, blending Hindu-Muslim architecture',
            },
            {
                'name': 'Champaner-Pavagadh Archaeological Park',
                'category': 'heritage',
                'note': 'UNESCO World Heritage site near Kalol with medieval ruins, temples and mosques',
            },
            {
                'name': 'Pavagadh Hill',
                'category': 'nature',
                'note': 'UNESCO-listed hill ~30 km away with ancient temples, a fort complex and scenic views',
            },
        ],
        'food': [
            {
                'dish': 'Gujarati Thali',
                'where': 'local restaurants in Kalol',
                'note': 'Staple meal with dal, sabzi, roti, rice and dhokla',
            },
            {
                'dish': 'Khaman Dhokla',
                'where': 'street food stalls',
                'note': 'Popular steamed savory snack widely sold locally',
            },
            {
                'dish': 'Dabeli',
                'where': 'street food stalls',
                'note': 'Common Gujarati street snack found around town',
            },
            {
                'dish': 'Khichu and Bhajiya',
                'where': 'local fast food joints',
                'note': 'Everyday tea-time snacks popular in Kalol',
            },
            {
                'dish': 'Gud Papri and Sutarfeni/Son Papdi',
                'where': 'local sweet shops',
                'note': 'Traditional Gujarati sweets recommended for visitors',
            },
        ],
    },
    'keshod': {
        'character': 'Keshod is a small taluka town in Junagadh district, Gujarat, known mainly as a gateway near Gir forest and for local Chhakado rickshaw manufacturing rather than for its own tourist sites.',
        'landmarks': [
            {
                'name': 'Gir National Park (nearby)',
                'category': 'wildlife',
                'note': 'Home to the Asiatic lion; Keshod serves as a nearby gateway/airport town for visitors',
            },
            {
                'name': 'Girnar Hill (nearby, ~70 km)',
                'category': 'religious',
                'note': 'Sacred Hindu and Jain pilgrimage mountain with ancient roots and trekking trails',
            },
        ],
        'food': [
            {
                'dish': 'Gujarati Thali',
                'where': 'local eateries in Keshod',
                'note': 'Traditional full-course Gujarati meal recommended for an authentic regional taste',
            },
        ],
    },
    'kheda': {
        'character': 'A historic town in central Gujarat known for its role in the Indian independence movement (Kheda Satyagraha, 1918) and its cluster of temples and old forts.',
        'landmarks': [
            {
                'name': 'Kheda Fort',
                'category': 'heritage',
                'note': 'Historical fort offering panoramic views of the town',
            },
            {
                'name': 'Swaminarayan Temple',
                'category': 'religious',
                'note': 'Ancient temple noted for its architectural beauty',
            },
            {
                'name': 'Gandhi Ashram, Kheda',
                'category': 'heritage',
                'note': 'Historically significant site tied to the Kheda Satyagraha of 1918 led by Gandhi',
            },
            {
                'name': 'Galteshwar',
                'category': 'religious',
                'note': 'Important historic and religious site located in Thasra taluka of Kheda district',
            },
        ],
        'food': [
            {
                'dish': 'Dhokla',
                'where': 'Local eateries, Kheda town',
                'note': 'Steamed fermented chickpea cake, a Gujarati staple famous here',
            },
            {
                'dish': 'Khandvi',
                'where': 'Local eateries, Kheda town',
                'note': 'Savory rolled snack made from gram flour',
            },
            {
                'dish': 'Ghari',
                'where': 'Local sweet shops',
                'note': 'Rich sweet made with ghee, sugar and fillings, popular during festivities',
            },
            {
                'dish': 'Basundi',
                'where': 'Local sweet shops',
                'note': 'Sweetened condensed milk dessert',
            },
            {
                'dish': 'Mohanthal',
                'where': 'Local sweet shops',
                'note': 'Traditional gram-flour based Gujarati sweet',
            },
        ],
    },
    'khambhalia': {
        'character': 'Khambhalia (Jamkhambhaliya) is a small historic Gujarat town in Devbhumi Dwarka district known as the "Butter City" for its ghee, with old town gates and lakeside temples.',
        'landmarks': [
            {
                'name': 'Ghee Dam',
                'category': 'nature',
                'note': "Named for the town's famous ghee; popular for sunrise and sunset views, and overflows white like ghee",
            },
            {
                'name': 'Anand Baug',
                'category': 'garden',
                'note': 'Garden beside Ghee Dam, popular picnic spot',
            },
            {
                'name': 'Shiru Lake & Shiva Temple',
                'category': 'religious',
                'note': 'Lake with a prehistoric Shiva temple; hosts an annual fair around Janmashtami',
            },
            {
                'name': 'Five Historic Gates (Nagar, Salaya, Por, Dwarka, Jodhpur Gate)',
                'category': 'heritage',
                'note': "Old town gates that define Khambhalia's traditional layout",
            },
            {
                'name': 'Aradhna Dhaam',
                'category': 'religious',
                'note': 'Large Jain temple and art gallery about 11 km outside town on the Jamnagar road',
            },
        ],
        'food': [
            {
                'dish': 'Ghee',
                'where': 'Local dairies/markets',
                'note': "Khambhalia is famed nationwide for high-quality ghee, earning it the nickname 'Butter City'",
            },
            {
                'dish': 'Undhiyu',
                'where': 'Local Gujarati eateries',
                'note': 'Winter specialty of mixed vegetables and fenugreek dumplings slow-cooked with coconut and spices',
            },
            {
                'dish': 'Gujarati thali',
                'where': 'Local thali restaurants',
                'note': 'Array of dishes including dhokla and khandvi served on one platter',
            },
            {
                'dish': 'Fafda-jalebi',
                'where': 'Roadside/street vendors',
                'note': 'Popular street food combo sold by local vendors',
            },
        ],
    },
    'lunawada': {
        'character': "A small heritage town in Gujarat's Mahisagar district known for ancient Shiva temples, riverside spots, and gardens.",
        'landmarks': [
            {
                'name': 'Luneshwar Mahadev Temple',
                'category': 'religious',
                'note': 'Ancient Shiva temple linked to legends of the Pandavas dwelling here during their forest exile.',
            },
            {
                'name': 'Kaleshwari',
                'category': 'heritage',
                'note': 'Historic site with footprints attributed to Bhima, an old Shiva temple, ancient wells, and a water kund.',
            },
            {
                'name': 'Kadana Dam',
                'category': 'nature',
                'note': 'Large dam on the Mahi River near Lunawada, popular for scenic views.',
            },
            {
                'name': 'Kakachiya Triveni Sangam',
                'category': 'religious',
                'note': 'Sacred confluence point (triveni sangam) near Lunawada.',
            },
            {
                'name': 'Panam River Check Dam & Panam Bridge',
                'category': 'nature',
                'note': 'Riverside spots along the Panam River popular with local visitors.',
            },
            {
                'name': 'Jahavar Garden / Fateh Baug',
                'category': 'garden',
                'note': 'Local public gardens used for relaxation and family outings.',
            },
            {
                'name': 'Ramji Mandir',
                'category': 'religious',
                'note': 'Notable local temple in Lunawada town.',
            },
        ],
        'food': [
            {
                'dish': 'Dhokla',
                'where': 'Local eateries and sweet shops in Lunawada',
                'note': 'Classic Gujarati steamed snack widely enjoyed in the region.',
            },
            {
                'dish': 'Khandvi',
                'where': 'Local eateries in Lunawada',
                'note': 'Popular Gujarati rolled gram-flour snack.',
            },
            {
                'dish': 'Traditional Gujarati Thali',
                'where': 'Local thali restaurants in Lunawada',
                'note': 'Multi-dish vegetarian thali representative of regional home-style cooking.',
            },
        ],
    },
    'mansa': {
        'character': 'Mansa is a small former princely-state town in Gandhinagar district, Gujarat, ruled historically by the Chavda Rajputs, with modest tourism coverage focused on its old temples and stepwell.',
        'landmarks': [
            {
                'name': 'Mansa Stepwell',
                'category': 'heritage',
                'note': 'An ancient stepwell (vav) about 5.40 diameter with idols of Amba and Bhairava in niches and a 28-line inscription.',
            },
            {
                'name': 'Govardhannath Havelis and Vaishnava Temples',
                'category': 'religious',
                'note': "Old Vaishnava temples and havelis dedicated to Govardhannath, reflecting the town's religious heritage.",
            },
            {
                'name': 'Former Mansa State Royal Heritage',
                'category': 'heritage',
                'note': 'Mansa was the capital of the princely Mansa State ruled by the Chavda Rajputs, giving the town its historic character.',
            },
        ],
        'food': [],
    },
    'kheralu': {
        'character': 'Kheralu is a small, quiet heritage-adjacent town in Mehsana district, Gujarat, notable mainly as a base near larger regional temple and heritage sites rather than for major attractions of its own.',
        'landmarks': [
            {
                'name': 'Bahuchar Mata Temple',
                'category': 'religious',
                'note': 'Well-known Shakti Peeth temple in the Kheralu/Mehsana area, a major regional pilgrimage site',
            },
            {
                'name': 'Taranga Hills (Taranga Jain Temple)',
                'category': 'religious',
                'note': 'Historic Jain temple complex on a hill near Kheralu, significant for Jain pilgrims',
            },
            {
                'name': 'Modhera Sun Temple',
                'category': 'heritage',
                'note': 'Renowned 11th-century Sun Temple with intricate stepped tank architecture, in the wider Kheralu/Mehsana region',
            },
            {
                'name': 'Dharoi Dam',
                'category': 'nature',
                'note': 'Dam and reservoir near Kheralu popular for scenic views and day trips',
            },
        ],
        'food': [
            {
                'dish': 'Undhiyu',
                'where': 'local Gujarati eateries in town',
                'note': 'Signature mixed-vegetable winter dish of the wider Mehsana/North Gujarat region',
            },
            {
                'dish': 'Fafda-Gathiya',
                'where': 'local snack shops',
                'note': 'Common Gujarati breakfast/snack specialty popular across the Mehsana district',
            },
            {
                'dish': 'Mehsani dairy (milk, ghee, buttermilk)',
                'where': 'local dairies and markets',
                'note': 'Region famous for rich dairy from the Mehsani buffalo breed',
            },
        ],
    },
    'limbdi': {
        'character': 'Limbdi is a small historic town in Surendranagar district, Gujarat, known for its temples and heritage from the princely state era.',
        'landmarks': [
            {
                'name': 'BAPS Shri Swaminarayan Temple',
                'category': 'religious',
                'note': 'Popular Vaishnavite pilgrimage site in Limbdi',
            },
            {
                'name': 'Jain Temple (1,100 years old)',
                'category': 'religious',
                'note': "Ancient Jain temple, one of the town's oldest heritage structures",
            },
            {
                'name': 'Jasma Odan Temple',
                'category': 'religious',
                'note': 'Local temple tied to Gujarati folklore',
            },
            {
                'name': 'Gandhi Smruti Mandir',
                'category': 'heritage',
                'note': 'Memorial site commemorating Gandhi',
            },
            {
                'name': 'Saurashtra Nimbank Peeth',
                'category': 'religious',
                'note': 'Religious institution/monastery in Limbdi',
            },
            {
                'name': 'Local bazaars',
                'category': 'shopping',
                'note': 'Markets selling traditional handicrafts, textiles, and jewelry',
            },
            {
                'name': 'Little Rann of Kutch',
                'category': 'wildlife',
                'note': 'Nearby salt marsh sanctuary home to the Indian Wild Ass and migratory birds',
            },
        ],
        'food': [
            {
                'dish': 'Dhokla',
                'where': 'local eateries',
                'note': 'Savory steamed cake, a staple Gujarati snack',
            },
            {
                'dish': 'Undhiyu',
                'where': 'local eateries',
                'note': 'Mixed vegetable dish traditionally cooked in earthen pots',
            },
            {
                'dish': 'Thepla',
                'where': 'local eateries',
                'note': 'Spiced flatbread, popular Kathiyawadi item',
            },
            {
                'dish': 'Gathiya',
                'where': 'local markets',
                'note': 'Fried gram-flour snack served with chutney',
            },
        ],
    },
    'mandvi': {
        'character': 'Mandvi is a historic coastal port town in Kutch, Gujarat, known for its golden beach, shipbuilding heritage, and royal architecture.',
        'landmarks': [
            {
                'name': 'Mandvi Beach',
                'category': 'beach',
                'note': 'Famous golden-sand beach known for camel/horse rides, water sports, windfarm views, and stunning sunsets',
            },
            {
                'name': 'Vijay Vilas Palace',
                'category': 'heritage',
                'note': 'Grand 1929 Rajput-style royal palace, featured in Bollywood films like Hum Dil De Chuke Sanam',
            },
            {
                'name': '72 Jinalaya Temple',
                'category': 'religious',
                'note': 'Jain temple complex with 72 shrines (Deris) dedicated to Lord Mahavira',
            },
            {
                'name': 'Mandvi Shipyards',
                'category': 'attraction',
                'note': 'Traditional wooden dhow-building yards showcasing centuries-old shipbuilding craft',
            },
            {
                'name': 'Topansar Lake',
                'category': 'nature',
                'note': 'Small scenic lake located in the heart of the old town',
            },
            {
                'name': 'Shyamji Krishna Varma Smarak',
                'category': 'museum',
                'note': 'Memorial and museum honoring the freedom fighter and scholar',
            },
            {
                'name': 'Mandvi Fort',
                'category': 'heritage',
                'note': '17th-century fort ruins with a lighthouse overlooking the coastline',
            },
        ],
        'food': [
            {
                'dish': 'Dabeli',
                'where': 'Local street stalls around town',
                'note': 'Popular street snack reportedly invented in Mandvi in the 1960s',
            },
            {
                'dish': 'Gathiya',
                'where': 'Local shops/street vendors',
                'note': 'Crispy gram-flour snack common in Kutchi cuisine',
            },
            {
                'dish': 'Fresh seafood',
                'where': 'Roadside stalls and eateries near Mandvi Beach',
                'note': "Coastal seafood served fresh given the town's port location",
            },
        ],
    },
    'kodinar': {
        'character': 'Kodinar is a small coastal pilgrimage town in Gir Somnath district, known for its cluster of ancient Shiva temples and proximity to Krishna-related sacred sites and the Gir forest.',
        'landmarks': [
            {
                'name': 'Bapeshwar Shiva Temple',
                'category': 'religious',
                'note': "Renowned Shiva temple with a local legend that Mahmud of Ghazni's attempt to destroy the shivling failed after a sudden bee attack",
            },
            {
                'name': 'Bhalka Tirth',
                'category': 'religious',
                'note': "Sacred site believed to be where Lord Krishna was struck by a hunter's arrow and left his earthly form",
            },
            {
                'name': 'Mul Dwarka',
                'category': 'religious',
                'note': 'Nearby coastal village claiming to be one of the three original Dwarka sites, with an ancient Krishna temple dating to the 10th century AD',
            },
            {
                'name': 'Jamjir Waterfalls',
                'category': 'nature',
                'note': 'Local waterfall spot popular for a nature outing near Kodinar',
            },
            {
                'name': 'Devayat Bodar Dham',
                'category': 'religious',
                'note': 'Local shrine/dham that draws devotees in the Kodinar area',
            },
            {
                'name': 'Kodinar Park',
                'category': 'garden',
                'note': 'Local park in the town used for leisure and recreation',
            },
            {
                'name': 'Gir Forest National Park',
                'category': 'wildlife',
                'note': 'Nearby sanctuary and last refuge of the wild Asiatic lion, reachable from Kodinar',
            },
        ],
        'food': [],
    },
    'mahuva': {
        'character': 'A serene coastal town in Bhavnagar district known as the "Kashmir of Saurashtra" for its lush coconut and mango groves, temples, and calm beach.',
        'landmarks': [
            {
                'name': 'Mahuva Beach',
                'category': 'beach',
                'note': 'Calm, relatively unexplored beach known for scenic sunsets and a peaceful shoreline stroll',
            },
            {
                'name': 'Bhavani Mata Temple',
                'category': 'religious',
                'note': 'Historic temple perched atop a dune at the edge of the beach, dedicated to Goddess Bhavani',
            },
            {
                'name': 'Shree Swaminarayan Mandir',
                'category': 'religious',
                'note': 'Prominent temple known for intricate carvings, peaceful ambiance, and vibrant festivals',
            },
            {
                'name': 'Nichla Mandir',
                'category': 'heritage',
                'note': 'Ancient temple noted for its historical and architectural significance',
            },
            {
                'name': 'Nishkalank Mahadev Temple',
                'category': 'religious',
                'note': 'Shiva temple accessible only during low tide, with simple yet striking architecture',
            },
        ],
        'food': [
            {
                'dish': 'Jamadar Mangoes',
                'where': 'Local mango groves and markets around Mahuva',
                'note': 'Unique local mango variety, often compared to Alphonso, grown only in this area',
            },
            {
                'dish': 'Red Onions',
                'where': 'Mahuva market yard',
                'note': 'Mahuva hosts the second-largest onion trading centre in India after Lasalgaon, known for good-quality red onions',
            },
            {
                'dish': 'Coconut-based produce',
                'where': 'Coastal groves around town',
                'note': 'Abundant coconut plantations feed into local Gujarati cooking and produce',
            },
            {
                'dish': 'Seafood',
                'where': 'Coastal eateries near the beach',
                'note': 'Coastal location supports fresh seafood dishes alongside traditional Gujarati thali fare',
            },
        ],
    },
    'mithapur': {
        'character': 'A small industrial coastal town built around the Tata Chemicals salt and soda ash works, with a beach, lighthouse and lakes on its outskirts, near the pilgrimage town of Dwarka.',
        'landmarks': [
            {
                'name': 'Mithapur Lighthouse',
                'category': 'nature',
                'note': '19th-century lighthouse on a small hilltop offering panoramic views of the Arabian Sea and coastline',
            },
            {
                'name': 'Mithapur Beach',
                'category': 'beach',
                'note': "A roughly 2-km-long beach along the town's coastline",
            },
            {
                'name': 'Mithapur Lakes',
                'category': 'nature',
                'note': "Two lakes on the town's outskirts that attract migratory birds in winter",
            },
            {
                'name': 'Tata Chemicals Plant',
                'category': 'attraction',
                'note': 'Landmark salt and soda ash works founded in 1939 that the town was built around',
            },
            {
                'name': 'Local temples and gardens',
                'category': 'religious',
                'note': "Several Hindu temples plus parks/gardens serving the town's residents",
            },
        ],
        'food': [],
    },
    'mangrol': {
        'character': 'A quaint coastal fishing town in Junagadh district on the Arabian Sea, known for its harbor and beaches.',
        'landmarks': [
            {
                'name': 'Mangrol Beach',
                'category': 'beach',
                'note': 'popular coastal beach known for its serene environment and stunning sunsets',
            },
            {
                'name': 'Ahmedpur Mandvi Beach',
                'category': 'beach',
                'note': 'nearby beach attraction popular with visitors to the Mangrol area',
            },
            {
                'name': 'Damodar Kund',
                'category': 'religious',
                'note': 'nearby sacred water tank/pilgrimage spot',
            },
            {
                'name': 'Uparkot Fort',
                'category': 'heritage',
                'note': 'historical fort in nearby Junagadh offering panoramic views of the surrounding landscape',
            },
        ],
        'food': [
            {
                'dish': 'Kesar mangoes',
                'where': 'local markets/orchards around Mangrol',
                'note': 'Mangrol is noted as famous for kesar mangoes',
            },
            {
                'dish': 'Fish curry and prawn masala',
                'where': 'coastal eateries',
                'note': 'fresh Arabian Sea catch prepared in local coastal style',
            },
            {
                'dish': 'Gujarati thali',
                'where': 'local restaurants',
                'note': 'traditional vegetarian thali with lentils, vegetables, and rotis',
            },
            {
                'dish': 'Dhokla, khandvi, samosas',
                'where': 'street food vendors',
                'note': 'common local street snacks in the area',
            },
        ],
    },
    'modasa': {
        'character': 'A temple town and district headquarters in Aravalli, known for its handloom industry and as a gateway to nearby Shamlaji pilgrimage sites.',
        'landmarks': [
            {
                'name': 'Modeshwari Mata Temple',
                'category': 'religious',
                'note': 'Temple within Modasa dedicated to the goddess Modeshwari, of great significance to the local community.',
            },
            {
                'name': 'Baba Ramdev Temple',
                'category': 'religious',
                'note': 'Prominent pilgrimage site dedicated to Baba Ramdev, drawing devotees from across India.',
            },
            {
                'name': 'Khanderay Temple',
                'category': 'religious',
                'note': 'Temple dedicated to Lord Khanderay (an incarnation of Shiva), a local symbol of devotion and heritage.',
            },
            {
                'name': 'Modasa Fort',
                'category': 'heritage',
                'note': "Ancient fort, now in ruins, offering panoramic views and a glimpse of the town's history.",
            },
            {
                'name': 'Kakdi Dam',
                'category': 'nature',
                'note': 'Scenic dam near Modasa popular as a picnic spot.',
            },
            {
                'name': 'Shamlaji Temple',
                'category': 'religious',
                'note': 'Ancient temple to Lord Vishnu about 20 km from Modasa, noted for intricate carvings.',
            },
        ],
        'food': [
            {
                'dish': 'Dhokla',
                'where': 'Local sweet/snack shops in Modasa',
                'note': 'Steamed fermented rice-and-chickpea cake, a staple Gujarati snack widely available in town.',
            },
            {
                'dish': 'Fafda-Jalebi',
                'where': 'Street food stalls, evening markets',
                'note': 'Classic Gujarati breakfast/snack combo sold at local street vendors.',
            },
            {
                'dish': 'Gujarati Thali',
                'where': 'Restaurants and dhabas in the town center',
                'note': 'Full vegetarian thali (including items like undhiyu, khandvi) served at local eateries.',
            },
        ],
    },
    'morbi': {
        'character': 'Morbi is a Gujarat industrial and heritage town on the Machhu River, known for its ceramics/clock industry alongside royal-era architecture like the Mani Mandir and the famous suspension bridge.',
        'landmarks': [
            {
                'name': 'Mani Mandir',
                'category': 'religious',
                'note': 'Multi-religious shrine built by the King of Morbi in memory of Queen Mani Ben, noted for its architecture.',
            },
            {
                'name': 'Morbi Suspension Bridge (Jhulta Pul)',
                'category': 'heritage',
                'note': 'British-era suspension bridge crossing the Machhu River into the old city.',
            },
            {
                'name': 'Darbargadh (Old Palace)',
                'category': 'heritage',
                'note': 'Former royal palace with an ornately carved front gate, now converted into a heritage hotel.',
            },
            {
                'name': 'Wellington Secretariat',
                'category': 'heritage',
                'note': 'Government building known for its architecture with strong Rajasthan influence.',
            },
            {
                'name': 'Clock Tower',
                'category': 'attraction',
                'note': 'British-era clock tower that serves as a historic landmark and hub of local activity.',
            },
        ],
        'food': [
            {
                'dish': 'Dhokla',
                'where': 'Local eateries across Morbi',
                'note': 'Steamed savory Gujarati snack cake, widely available.',
            },
            {
                'dish': 'Thepla / Fafda',
                'where': 'Local eateries across Morbi',
                'note': 'Traditional Gujarati snacks common in the local diet.',
            },
            {
                'dish': 'Morbi Wadi',
                'where': 'Local snack shops',
                'note': 'Spicy snack made from chickpea flour, associated with the town.',
            },
            {
                'dish': 'Ragda',
                'where': 'Street food stalls',
                'note': 'Fast food snack made from yellow peas, potatoes and spices.',
            },
            {
                'dish': 'Patra',
                'where': 'Local eateries',
                'note': 'Sweet, salty and spicy dish made from gram flour and spice mixture.',
            },
        ],
    },
    'petlad': {
        'character': 'Petlad is a small pilgrimage and market town in Anand district known for its temples and its agricultural/dairy and tobacco trade.',
        'landmarks': [
            {
                'name': 'Ranchhodraiji Temple',
                'category': 'religious',
                'note': 'Historic pilgrimage temple that draws devotees from across the region',
            },
            {
                'name': 'Swaminarayan Temple (Shri Swaminarayan Mandir)',
                'category': 'religious',
                'note': 'Intricately designed spiritual hub and revered pilgrimage site in Petlad',
            },
            {
                'name': 'Sardar Vallabhbhai Patel Statue',
                'category': 'heritage',
                'note': "Tribute statue located a short distance from Petlad, offering a glimpse into India's history",
            },
        ],
        'food': [
            {
                'dish': 'Dhokla',
                'where': 'Local eateries in Petlad',
                'note': 'Classic Gujarati steamed snack, commonly served across the town',
            },
            {
                'dish': 'Khandvi',
                'where': 'Local eateries in Petlad',
                'note': 'Traditional Gujarati rolled snack popular in local food spots',
            },
            {
                'dish': 'Thepla',
                'where': 'Local eateries in Petlad',
                'note': 'Everyday Gujarati flatbread dish, a regional staple',
            },
        ],
    },
    'padra': {
        'character': 'Padra is a small agricultural town near Vadodara, known for vegetable and toor dal farming and traditional gold/silver jewelry and leather footwear craft.',
        'landmarks': [
            {
                'name': 'Tulja Bhavani Temple',
                'category': 'religious',
                'note': 'Temple located in nearby Ranu village, a popular local pilgrimage spot.',
            },
            {
                'name': 'Padra Bird Sanctuary',
                'category': 'wildlife',
                'note': 'A haven for birdwatchers offering a chance to spot a variety of avian species.',
            },
            {
                'name': 'Narmada River',
                'category': 'nature',
                'note': 'Riverside spot near Padra good for a leisurely, serene stroll.',
            },
            {
                'name': 'Nandalay Haveli',
                'category': 'heritage',
                'note': 'Local heritage haveli mentioned as a nearby attraction.',
            },
            {
                'name': 'Old Padra Road',
                'category': 'shopping',
                'note': 'Street lined with food stalls and local shops popular with residents.',
            },
        ],
        'food': [
            {
                'dish': 'Dhokla',
                'where': 'Old Padra Road food stalls',
                'note': 'Classic Gujarati steamed snack, a local favorite in the area.',
            },
            {
                'dish': 'Khandvi',
                'where': 'Old Padra Road food stalls',
                'note': 'Rolled gram-flour snack commonly found among local eateries.',
            },
            {
                'dish': 'Fafda',
                'where': 'Old Padra Road food stalls',
                'note': 'Crispy gram-flour snack popular as a regional specialty.',
            },
            {
                'dish': 'Toor Dal',
                'where': 'Local markets/farms around Padra',
                'note': 'Padra is well known for producing high-quality toor dal, a Gujarati kitchen staple.',
            },
        ],
    },
    'radhanpur': {
        'character': 'A small historic Patan-district town in Gujarat with modest, thin tourism coverage online — chiefly known regionally rather than as a major tourist destination.',
        'landmarks': [
            {
                'name': 'Vrindavan Society area',
                'category': 'attraction',
                'note': 'listed among local points of interest near Radhanpur',
            },
            {
                'name': 'Javantri',
                'category': 'nature',
                'note': 'nearby locality noted as a visited spot around Radhanpur',
            },
            {
                'name': 'Zazam',
                'category': 'attraction',
                'note': 'nearby village/area listed as a local attraction',
            },
        ],
        'food': [
            {
                'dish': 'Gujarati Thali (rotli, dal, sabzi, kadhi)',
                'where': 'local restaurants in Radhanpur',
                'note': 'standard regional Gujarati home-style meal served widely in the area',
            },
            {
                'dish': 'Khaman / Khandvi',
                'where': 'local eateries',
                'note': 'common Gujarati snack items available in the region, not specific to Radhanpur alone',
            },
        ],
    },
    'rajula': {
        'character': 'Rajula is a small industrial/coastal town in Amreli District, Gujarat, near the Arabian Sea, mainly known as a gateway to nearby Shiva temples and the Diu coastline rather than as a tourist destination itself.',
        'landmarks': [
            {
                'name': 'Kumbhnath Sukhnath Temple',
                'category': 'religious',
                'note': 'Old Shiva temple located near Rajula',
            },
            {
                'name': 'Dhatarwadi Riverside',
                'category': 'nature',
                'note': 'Riverside spot opposite the Kumbhnath Temple, a local tourist attraction',
            },
            {
                'name': 'Chachudeshwar Mahadev Temple',
                'category': 'religious',
                'note': 'Coastal temple about 10 km from the city where the Dhatarwadi river meets the Arabian Sea',
            },
        ],
        'food': [
            {
                'dish': 'Thepla',
                'where': 'local area',
                'note': 'Thin, spiced Gujarati flatbread, a regional favorite',
            },
            {
                'dish': 'Khandvi',
                'where': 'local area',
                'note': 'Silky rolled snack made from gram flour and buttermilk',
            },
            {
                'dish': 'Undhiyu',
                'where': 'local area',
                'note': 'Slow-cooked mixed vegetable dish popular in winter, typical of the wider Gujarat region',
            },
        ],
    },
    'salaya': {
        'character': 'Salaya is a small coastal port town in Jamnagar/Devbhoomi Dwarka district of Gujarat, historically known as a hub for salt trade, shipbuilding, and fishing.',
        'landmarks': [
            {
                'name': 'Rukmini Temple',
                'category': 'religious',
                'note': 'Ancient temple dedicated to Rukmini, consort of Lord Krishna, known for its intricate architecture and religious ceremonies.',
            },
            {
                'name': 'Salaya Museum',
                'category': 'museum',
                'note': "Showcases artifacts and exhibits highlighting the region's cultural heritage.",
            },
        ],
        'food': [
            {
                'dish': 'Fresh seawater fish',
                'where': 'local fishing harbour/markets',
                'note': 'Salaya is a fishing port known for a variety of fresh, high-quality seawater fish.',
            },
            {
                'dish': 'Traditional Gujarati thali',
                'where': 'local eateries',
                'note': 'Standard regional Gujarati fare available across town.',
            },
        ],
    },
    'rajpipla': {
        'character': 'Rajpipla is a former princely state capital in Narmada district, Gujarat, known for its royal palace, nearby wildlife sanctuaries and waterfalls, and its proximity to the Sardar Sarovar Dam / Statue of Unity area.',
        'landmarks': [
            {
                'name': 'Rajvant Palace',
                'category': 'heritage',
                'note': 'Royal residence of the former Rajpipla princely state, now offering guided tours of its history and architecture',
            },
            {
                'name': 'Shoolpaneshwar Wildlife Sanctuary',
                'category': 'wildlife',
                'note': 'Sanctuary near Rajpipla offering safaris to spot sloth bears, leopards and varied birdlife',
            },
            {
                'name': 'Ratanmahal Sloth Bear Sanctuary',
                'category': 'wildlife',
                'note': 'Sanctuary known for sloth bear sightings near Rajpipla',
            },
            {
                'name': 'Zarwani Waterfall',
                'category': 'nature',
                'note': 'Waterfall in the surrounding forests, popular for picnics, photography and nature walks',
            },
            {
                'name': 'Nilkanth Dham, Poicha',
                'category': 'religious',
                'note': 'Large temple complex near Rajpipla with gardens and space for prayer',
            },
            {
                'name': 'Harsiddhi Mataji Temple',
                'category': 'religious',
                'note': 'Temple located within Rajpipla city',
            },
            {
                'name': 'Sardar Sarovar Dam',
                'category': 'attraction',
                'note': "Major dam near Rajpipla with guided tours covering a garden, Nehru's foundation-stone site, a boating lake and a trekking/nature-education site",
            },
        ],
        'food': [
            {
                'dish': 'Chicken Rajpipla',
                'where': 'Local restaurants in Rajpipla',
                'note': 'Royal dish created for Maharaja Vijaysinhji, believed to be influenced by his travels to Britain; later adopted widely in Parsi homes',
            },
            {
                'dish': 'Undhiyu',
                'where': 'General area',
                'note': 'Mixed vegetable dish cooked with spices, a traditional Gujarati winter specialty found in the region',
            },
            {
                'dish': 'Dhokla',
                'where': 'General area',
                'note': 'Steamed savory cake of fermented rice and chickpea batter, a staple Gujarati snack in the area',
            },
            {
                'dish': 'Thepla and khakhra',
                'where': 'General area',
                'note': 'Everyday Gujarati flatbread staples common in local cuisine',
            },
        ],
    },
    'sanand': {
        'character': "Sanand is a small industrial town near Ahmedabad, known as Gujarat's automobile manufacturing hub, with limited standalone tourism and mostly serving as a gateway to nearby Ahmedabad-area attractions.",
        'landmarks': [
            {
                'name': 'Nalsarovar Bird Sanctuary',
                'category': 'wildlife',
                'note': 'UNESCO-recognized Ramsar wetland site near Sanand, a major base for birdwatchers',
            },
            {
                'name': 'Adalaj Stepwell',
                'category': 'heritage',
                'note': 'Ornate ancient stepwell near Sanand, noted as a must-visit example of historic Indian architecture',
            },
            {
                'name': 'Sabarmati Ashram',
                'category': 'heritage',
                'note': "Mahatma Gandhi's historic ashram, a short trip from Sanand in Ahmedabad",
            },
        ],
        'food': [
            {
                'dish': 'Gujarati thali',
                'where': 'Local Sanand restaurants',
                'note': "Search results show Sanand's dining scene is mixed North Indian, Rajasthani, Kathiyawadi and Gujarati cuisine rather than one signature dish",
            },
            {
                'dish': 'Kathiyawadi cuisine',
                'where': 'Local Sanand eateries',
                'note': 'Listed among the popular cuisine categories served in Sanand restaurants',
            },
        ],
    },
    'ranavav': {
        'character': 'Ranavav is a small municipal town in Porbandar district, Gujarat, notable mainly for the Jambavant Cave and its edge-of-Barda-hills location, with most dining/food culture shared with nearby Porbandar city.',
        'landmarks': [
            {
                'name': 'Jambavant Cave (Jambuvant ki Gufa)',
                'category': 'heritage',
                'note': 'Historic cave in the Barda Hills near Ranavav, associated in local lore with the Mahabharata figure Jambavant.',
            },
            {
                'name': 'Khambhalida Caves',
                'category': 'heritage',
                'note': 'Group of three 4th-century Buddhist caves with carved sculptures, located in the Ranavav area.',
            },
            {
                'name': 'Barda Wildlife Sanctuary',
                'category': 'wildlife',
                'note': 'Nearby sanctuary in the Barda hills range (extending Jamnagar to Porbandar) home to leopards, chinkaras and varied birdlife.',
            },
            {
                'name': 'Khimeshwar Temple',
                'category': 'religious',
                'note': 'Local temple in Ranavav noted in regional travel guides.',
            },
        ],
        'food': [
            {
                'dish': 'Undhiyu',
                'where': 'Porbandar-Ranavav region',
                'note': 'Winter Gujarati vegetable and fenugreek-dumpling delicacy slow-cooked in an earthen pot.',
            },
            {
                'dish': 'Handvo',
                'where': 'local eateries',
                'note': 'Savory baked lentil-and-rice cake flavored with sesame and mustard seeds.',
            },
            {
                'dish': 'Dhokla and Khandvi',
                'where': 'local eateries',
                'note': 'Classic steamed Gujarati snacks common across the region.',
            },
            {
                'dish': 'Seafood (prawns, pomfret)',
                'where': 'Porbandar coast, near Ranavav',
                'note': "Grilled or spicy tomato-onion cooked prawns and pomfret, reflecting the coastal district's fishing tradition.",
            },
            {
                'dish': 'Ganthia, sev, farshan',
                'where': 'local street stalls',
                'note': 'Popular fried Gujarati snack items sold widely in the district.',
            },
        ],
    },
    'rapar': {
        'character': 'Rapar is a small historical town in the Vagad region of Kutch district, serving as the closest gateway town on the route to the Dholavira Harappan site, known for its temples and proximity to the Rann of Kutch.',
        'landmarks': [
            {
                'name': 'Bhujia Fort',
                'category': 'heritage',
                'note': "Historic fort associated with the region's ancient architecture",
            },
            {
                'name': 'Shri Ramnathji Mandir',
                'category': 'religious',
                'note': 'A peaceful temple noted for its serene setting and architecture',
            },
            {
                'name': 'Vraj Temple',
                'category': 'religious',
                'note': 'Temple set amid lush greenery, popular for spirituality and architecture',
            },
            {
                'name': 'Jain Temples of Rapar',
                'category': 'religious',
                'note': "Local Jain temples reflecting the town's cultural heritage",
            },
            {
                'name': 'Rapar Talav',
                'category': 'nature',
                'note': 'A scenic lake/tank spot popular with nature lovers for tranquil views',
            },
            {
                'name': 'Kutch Museum (nearby)',
                'category': 'museum',
                'note': "Regional museum showcasing Kutch's history and Harappan-era connections; Rapar is the gateway town to Dholavira",
            },
        ],
        'food': [
            {
                'dish': 'Kutchi Dabeli',
                'where': 'local street food stalls',
                'note': 'Spicy-sweet potato filling in a bun with chutneys, pomegranate seeds and sev',
            },
            {
                'dish': 'Bajra na Rotla',
                'where': 'local eateries/homes',
                'note': 'Traditional pearl-millet flatbread, a Kutch region staple',
            },
            {
                'dish': 'Kathiyawadi Thali',
                'where': 'local dhabas/restaurants',
                'note': 'Full platter with bajra roti, spiced potatoes, khichdi and kadhi',
            },
            {
                'dish': 'Khaman Dhokla and Thepla',
                'where': 'local shops',
                'note': 'Common Gujarati snacks widely eaten across the Kutch/Vagad region',
            },
        ],
    },
    'savarkundla': {
        'character': 'A small twin town (Savar + Kundla merged) in Amreli district, Gujarat, known as a major hub for weighing-scale manufacturing and surrounded by Kesar mango farmland.',
        'landmarks': [
            {
                'name': 'BAPS Shri Swaminarayan Mandir',
                'category': 'religious',
                'note': 'Well-regarded local temple, a top-rated attraction in Savarkundla',
            },
            {
                'name': 'Tulsi Shyam Springs',
                'category': 'nature',
                'note': 'Natural hot springs and geysers near Savarkundla, a popular nearby nature attraction',
            },
        ],
        'food': [
            {
                'dish': 'Dhosa (dosa)',
                'where': 'Local vegetarian restaurants in Savarkundla',
                'note': 'Cited as a popular local food specialty in the town',
            },
            {
                'dish': 'Kesar mango',
                'where': 'Amreli district surrounding farmland',
                'note': 'The wider district is known for Kesar mango cultivation',
            },
        ],
    },
    'sidhpur': {
        'character': 'Sidhpur is a historic Gujarati town in Patan district known for its sacred Bindu Sarovar lake, ruined Rudra Mahalaya Shiva temple, and grand Bohra Vad mansions built by wealthy Dawoodi Bohra families in Victorian architectural style.',
        'landmarks': [
            {
                'name': 'Bindu Sarovar',
                'category': 'religious',
                'note': "Sacred lake believed to be where Lord Parshuram performed his mother's last rites; major pilgrimage site.",
            },
            {
                'name': 'Rudra Mahalaya Temple',
                'category': 'heritage',
                'note': '12th-century Shiva temple built by Raja Siddhraj, with a three-storeyed shikhara, 1600 pillars and 12 entrance doors; one of only five Swayambhu Shiva temples in India.',
            },
            {
                'name': 'Bohra Vad',
                'category': 'heritage',
                'note': 'Rows of grand late-19th/early-20th-century mansions built by wealthy Dawoodi Bohra families in Victorian architectural style.',
            },
            {
                'name': 'Sidhpur Stepwell',
                'category': 'heritage',
                'note': 'Ancient stepwell noted for intricate carvings, reflecting traditional Indian water-conservation architecture.',
            },
        ],
        'food': [
            {
                'dish': 'Traditional Gujarati cuisine',
                'where': 'Local restaurants and street food stalls in Sidhpur',
                'note': 'Search results note Sidhpur town for general Gujarati fare rather than a named signature dish; no specific local specialty was found tied uniquely to Sidhpur.',
            },
        ],
    },
    'sihor': {
        'character': "Sihor is a temple-dotted pilgrimage and hill town near Bhavnagar, known for its 12th-century tank, cave shrine legends, and a heritage Maharajah's palace.",
        'landmarks': [
            {
                'name': 'Brahma Kund',
                'category': 'heritage',
                'note': '12th-century stepped tank surrounded by idols of Hindu deities',
            },
            {
                'name': 'Gautameshwar Temple and Lake',
                'category': 'religious',
                'note': 'Cave shrine with a Swayambhu Shivling, linked to the legend of Gautama Maharishi, Ahalya and Lord Rama, and a secret tunnel legend to Somnath',
            },
            {
                'name': 'Vijay Vilas Palace',
                'category': 'heritage',
                'note': "17th-century Maharajahs' palace known for fine paintings and wood carvings",
            },
            {
                'name': 'Khodiyar Mata Temple',
                'category': 'religious',
                'note': 'Well-known local temple drawing pilgrims to the area',
            },
            {
                'name': 'Sihori Mata Temple',
                'category': 'religious',
                'note': 'Offers a panoramic view over Gautameshwar Lake and the whole town',
            },
            {
                'name': 'Saat Sheri',
                'category': 'nature',
                'note': 'Mountaintop/mound archaeological site, part of the Navnath pilgrimage of nine Shiva temples around town',
            },
            {
                'name': 'Sihor Hills',
                'category': 'nature',
                'note': 'Known for distinctive rock patterns and trekking around the town',
            },
        ],
        'food': [
            {
                'dish': 'Sihori Rajwadi Penda',
                'where': 'Local sweet shops in Sihor town',
                'note': "Sihor's most notable regional sweet delicacy, a traditional peda specialty tied to the town",
            },
        ],
    },
    'surendranagar': {
        'character': 'A district headquarters town in Gujarat known as a gateway to Kathiyawadi pilgrimage sites, with the Tarnetar Fair region and Chotila hill temple nearby, blended with everyday Gujarati/Kathiyawadi food culture.',
        'landmarks': [
            {
                'name': 'Trinetreshwar Mahadev Temple (Tarnetar)',
                'category': 'religious',
                'note': 'Ancient temple near Surendranagar famous for the vibrant annual Tarnetar Fair',
            },
            {
                'name': 'Chotila Hill (Chamunda Mataji Temple)',
                'category': 'religious',
                'note': 'Hilltop pilgrimage site with panoramic views, especially crowded during Navratri',
            },
            {
                'name': 'Ranmal Lake',
                'category': 'nature',
                'note': 'Tranquil lake in the heart of town, popular for boating, picnics, and photography',
            },
            {
                'name': 'Bajana Wildlife Sanctuary',
                'category': 'wildlife',
                'note': 'Sanctuary about 50 km away known for migratory birds and deer, best visited in winter',
            },
            {
                'name': 'Wadhwan',
                'category': 'heritage',
                'note': 'Historic twin town with old temples, palaces, and stepwells reflecting a bygone era',
            },
        ],
        'food': [
            {
                'dish': 'Sev Tameta',
                'where': 'Local Kathiyawadi eateries',
                'note': 'Spicy tomato curry with crispy sev, a Kathiyawadi staple',
            },
            {
                'dish': 'Ringan no Olo',
                'where': 'Local Kathiyawadi eateries',
                'note': 'Smoky roasted eggplant mash typical of Kathiyawadi cuisine',
            },
            {
                'dish': 'Bajra Roti',
                'where': 'Local homes and dhabas',
                'note': 'Pearl millet flatbread commonly paired with Kathiyawadi curries',
            },
            {
                'dish': 'Thepla',
                'where': 'Street food stalls',
                'note': 'Spiced flatbread made with fenugreek, a popular Gujarati snack/travel food',
            },
            {
                'dish': 'Dhokla and Khandvi',
                'where': 'Local sweet and farsan shops',
                'note': 'Classic steamed gram-flour Gujarati snacks widely sold across town',
            },
        ],
    },
    'songadh': {
        'character': 'A small tribal-heritage town in Tapi district known for its 18th-century Gaekwad-era fort overlooking the Tapi River and nearby forest waterfalls.',
        'landmarks': [
            {
                'name': 'Songadh Fort',
                'category': 'heritage',
                'note': '18th-century fort built by Pillaji Rao Gaekwad (1729-1766), with massive stone walls and 360-degree views over the Tapi River and forests.',
            },
            {
                'name': 'Gira Waterfalls',
                'category': 'nature',
                'note': "One of Gujarat's most scenic waterfalls, especially spectacular during the monsoon.",
            },
            {
                'name': 'Chimer Waterfall',
                'category': 'nature',
                'note': 'A more secluded, hidden-gem waterfall deeper in the forest near Songadh.',
            },
            {
                'name': 'Ukai Dam',
                'category': 'attraction',
                'note': 'Large earth-cum-masonry dam on the Tapi River with expansive backwater views.',
            },
            {
                'name': 'Purna Wildlife Sanctuary',
                'category': 'wildlife',
                'note': 'Nearby sanctuary home to leopards, deer, and diverse birdlife.',
            },
            {
                'name': 'Shree Mahaveer-Kundkund Digamber Jain Parmagam Mandir',
                'category': 'religious',
                'note': 'Notable Jain temple complex regarded as a center for Jain philosophy.',
            },
        ],
        'food': [
            {
                'dish': 'Undhiyu',
                'where': 'local eateries in Songadh',
                'note': 'Seasonal mixed-vegetable dish traditionally slow-cooked in an earthen pot, popular during festivals.',
            },
            {
                'dish': 'Dhokla',
                'where': 'local eateries in Songadh',
                'note': 'Steamed savory cake of fermented rice and chickpea batter, eaten as breakfast or snack.',
            },
            {
                'dish': 'Fafda-Jalebi',
                'where': 'local eateries in Songadh',
                'note': 'Classic Gujarati sweet-savory breakfast combo.',
            },
            {
                'dish': 'Roasted makai (corn) and imli snacks',
                'where': 'stalls near local attractions/waterfalls',
                'note': "Street snacks commonly sold at Songadh's tourist spots.",
            },
        ],
    },
    'umbergaon': {
        'character': 'A quiet coastal town in Valsad district known for its sandy beach with chowpatty-style street food and sunset views.',
        'landmarks': [
            {
                'name': 'Umbergaon Beach',
                'category': 'beach',
                'note': 'popular for golden sands, sunset views, lighthouse, fishing harbour, and horse carriage rides',
            },
            {
                'name': 'Shri Swaminarayan Mandir',
                'category': 'religious',
                'note': 'a peaceful temple known for its beauty and spirituality',
            },
            {
                'name': 'Daman Ganga River',
                'category': 'nature',
                'note': 'a serene riverside spot popular for nature lovers and pre-wedding photoshoots',
            },
        ],
        'food': [
            {
                'dish': 'Chowpatty-style street food (Bhelpuri, Panipuri, Sevpuri, Vada Pav)',
                'where': 'Umbergaon Beach',
                'note': 'the beach is famous for its chowpatty-style street food stalls',
            },
            {
                'dish': 'Khandvi',
                'where': 'local eateries',
                'note': 'a signature gram-flour and yogurt based snack of the town',
            },
        ],
    },
    'talaja': {
        'character': 'A small pilgrim town on Talaja Hill at the confluence of the Sarita and Shatrunjaya rivers, notable as a rare meeting point of Buddhist, Jain, and Hindu sacred sites and the birthplace of poet-saint Narsinh Mehta.',
        'landmarks': [
            {
                'name': 'Talaja Buddhist Caves',
                'category': 'heritage',
                'note': 'Roughly 2000-year-old rock-cut cave group on Talaja Hill with about 30 caves (15 used as water tanks); the Ebhala Mandapa hall with four octagonal pillars is the most impressive structure.',
            },
            {
                'name': 'Talaja Jain Tirth (Taldhwajgiri)',
                'category': 'religious',
                'note': "Ancient Jain pilgrimage site dedicated to Bhagwan Sumatinath, the 5th Tirthankar, said to have been built in the 12th century AD during King Kumarpal's era.",
            },
            {
                'name': 'Khodiyar Mata Temple',
                'category': 'religious',
                'note': 'Hindu temple on the same hill as the Buddhist caves and Jain tirth, making Talaja Hill a shared site for three religions.',
            },
            {
                'name': 'Talaja Hills',
                'category': 'nature',
                'note': 'Volcanic hill (about 350 feet) at the confluence of the Sarita and Shatrunjaya rivers, the birthplace of Gujarati poet-saint Narsinh Mehta.',
            },
        ],
        'food': [
            {
                'dish': 'Thepla',
                'where': 'Local eateries in Talaja town',
                'note': 'Common Saurashtra/Gujarati travel staple flatbread; general regional food, not Talaja-specific per available sources.',
            },
            {
                'dish': 'Dhokla',
                'where': 'Local sweet/snack shops in Talaja',
                'note': 'Widely eaten Gujarati steamed snack; search results describe it as a Gujarat-wide specialty rather than unique to Talaja.',
            },
        ],
    },
    'thangadh': {
        'character': 'Thangadh is a small industrial town in Surendranagar district, Gujarat, best known as a ceramics/pottery manufacturing hub rather than a major tourist destination, so verified tourism coverage is thin.',
        'landmarks': [
            {
                'name': 'Khodiyar Mata Temple',
                'category': 'religious',
                'note': 'Locally known temple noted for its architecture and spiritual significance',
            },
            {
                'name': 'Thangadh Fort',
                'category': 'heritage',
                'note': 'Lesser-known old fort ruins offering views of the surrounding landscape',
            },
            {
                'name': 'Stepwell of Thangadh',
                'category': 'heritage',
                'note': 'A historic stepwell noted for its distinctive design',
            },
            {
                'name': 'Pottery and ceramics workshops',
                'category': 'attraction',
                'note': 'Thangadh is a long-established hub for pottery/ceramics making; visitors can see local artisans at work',
            },
            {
                'name': 'Thangadh Reservoir',
                'category': 'nature',
                'note': 'A scenic spot mentioned for bird watching and picnics',
            },
        ],
        'food': [
            {
                'dish': 'Gujarati thali',
                'where': 'local restaurants',
                'note': 'Standard hearty thali of curries, lentils and flatbreads typical of the region',
            },
            {
                'dish': 'Dhokla, khandvi, fafda',
                'where': 'street food stalls',
                'note': 'Common Gujarati snacks sold widely in town',
            },
            {
                'dish': 'Jalebi and basundi',
                'where': 'local sweet shops',
                'note': 'Popular sweets mentioned in regional coverage of Thangadh',
            },
        ],
    },
    'tharad': {
        'character': 'Tharad is a small border town in Banaskantha district near the Rann of Kutch, known mainly as the gateway to the Nadabet BSF border tourism site and for its Jain temple, with thin dedicated tourism coverage beyond that.',
        'landmarks': [
            {
                'name': 'Shree Mota Mahaveer (Adinath) Jain Temple',
                'category': 'religious',
                'note': 'Major Jain pilgrimage temple known for intricate carvings and a peaceful atmosphere',
            },
            {
                'name': 'Seema Darshan, Nadabet',
                'category': 'attraction',
                'note': 'Border tourism site near Tharad where visitors watch BSF ceremonial activities at the India-Pakistan border',
            },
            {
                'name': 'Great Rann of Kutch (Tharad region)',
                'category': 'nature',
                'note': 'Vast salt desert on the edge of the Tharad area, striking white salt flats in the dry season',
            },
            {
                'name': 'Tharad Bazaar',
                'category': 'shopping',
                'note': "Local market for Bandhani fabrics, hand-embroidery ('Bharat Kaam'), and traditional Mojari footwear",
            },
        ],
        'food': [
            {
                'dish': 'Farsan (khaman, khandvi, dhokla, kachori)',
                'where': 'North Gujarat thali eateries',
                'note': 'Classic North Gujarat thali snacks commonly served in the Tharad area, not dishes unique to the town specifically',
            },
            {
                'dish': 'Thepla',
                'where': 'Local homes and eateries',
                'note': 'Spiced flatbread staple across North Gujarat including Tharad',
            },
            {
                'dish': 'Undhiyu',
                'where': 'Winter-season local eateries',
                'note': 'Slow-cooked mixed vegetable dish popular in North Gujarat during winter',
            },
        ],
    },
    'una': {
        'character': 'A small taluka town in Gir Somnath district, Saurashtra, notable mainly for its proximity to Diu, Gir National Park, and the Somnath coastline rather than attractions of its own.',
        'landmarks': [
            {
                'name': 'Delwada',
                'category': 'attraction',
                'note': 'Village in Una taluka, close to Diu, often visited en route between Una and Diu island.',
            },
            {
                'name': 'Gir National Park',
                'category': 'wildlife',
                'note': 'Nearby sanctuary in Gir Somnath district, the only place in the world with wild Asiatic lions.',
            },
            {
                'name': 'Somnath Temple & Beach',
                'category': 'religious',
                'note': 'One of the twelve Jyotirlinga shrines to Lord Shiva with an adjoining beach, located in the same district as Una.',
            },
        ],
        'food': [
            {
                'dish': 'Undhiyu',
                'where': 'Local Gujarati eateries',
                'note': 'Mixed-vegetable dish slow-cooked with winter produce, popular across the Saurashtra region including areas around Una.',
            },
            {
                'dish': 'Fafda-Jalebi',
                'where': 'Local sweet/snack shops',
                'note': 'Common Gujarati breakfast combo of crispy chickpea-flour fafda with sweet jalebi, widely eaten in this part of Gujarat.',
            },
        ],
    },
    'unjha': {
        'character': 'Known as Gujarat\'s "Spice City," Unjha is a North Gujarat pilgrim and trade town centered on the Umiya Mata Temple and its massive spice (cumin, fennel, mustard) APMC market.',
        'landmarks': [
            {
                'name': 'Shree Umiya Mata Temple',
                'category': 'religious',
                'note': "Grand temple dedicated to Goddess Umiya, the town's spiritual heart drawing millions of pilgrims, especially during Navratri.",
            },
            {
                'name': 'Kutchhadi Dada Temple',
                'category': 'religious',
                'note': 'Revered spiritual site in the heart of Unjha, known for its serene environment and architectural beauty.',
            },
            {
                'name': 'Hanuman Mandir',
                'category': 'religious',
                'note': 'Hindu temple dedicated to Lord Hanuman and a significant cultural landmark for the local community.',
            },
            {
                'name': 'Unjha APMC Spice Market',
                'category': 'shopping',
                'note': "One of Asia's largest spice markets, handling huge volumes of cumin, fennel, and mustard seed trade, giving Unjha its 'Spice City' name.",
            },
        ],
        'food': [
            {
                'dish': 'Jeera (cumin) and variyali (fennel) produce',
                'where': 'Unjha APMC Market',
                'note': 'Unjha is a major trading hub for cumin and fennel, staple spices central to Gujarati cuisine sourced and processed here.',
            },
        ],
    },
    'vadnagar': {
        'character': 'A small, ancient Gujarati town near Mehsana known as a historic archaeological hub and the childhood home of PM Narendra Modi.',
        'landmarks': [
            {
                'name': 'Kirti Toran',
                'category': 'heritage',
                'note': 'Pair of 12th-century, ~40-foot-tall red and yellow sandstone arch pillars on the bank of Sharmistha Lake.',
            },
            {
                'name': 'Hatkeshwar Temple',
                'category': 'religious',
                'note': 'Temple dedicated to Lord Shiva, noted for its ornate architecture and serene ambience.',
            },
            {
                'name': 'Kalika Mata Temple',
                'category': 'religious',
                'note': 'Hilltop temple offering panoramic views over the town.',
            },
            {
                'name': 'Sharmistha Lake / Vadnagar Lake',
                'category': 'nature',
                'note': 'Scenic lake popular for picnics and quiet nature time.',
            },
            {
                'name': 'Vadnagar Archaeological Experiential Museum',
                'category': 'museum',
                'note': "India's first experiential archaeological museum, inaugurated January 2025.",
            },
            {
                'name': 'Buddhist Caves',
                'category': 'heritage',
                'note': "Ancient caves in and around town tracing Gujarat's early Buddhist history.",
            },
            {
                'name': 'Vadnagar Railway Station',
                'category': 'attraction',
                'note': "Historic station (est. 1887) that has become a tourist draw tied to the town's most famous native son.",
            },
        ],
        'food': [
            {
                'dish': 'Local Gujarati snacks and tea',
                'where': 'Around town / with locals',
                'note': 'Search coverage was thin on named dishes; sources note Vadnagar is known for warm Gujarati hospitality, with locals offering visitors snacks and tea rather than a single signature dish being called out.',
            },
        ],
    },
    'vapi': {
        'character': "Vapi is an industrial town (near the Gujarat-Maharashtra border) that also serves as a gateway to nearby Daman's beaches and Udvada's Parsi pilgrimage site, rather than a major heritage destination itself.",
        'landmarks': [
            {
                'name': 'Nakshatra Garden',
                'category': 'garden',
                'note': 'Landscaped garden with a peaceful atmosphere, popular local relaxation spot.',
            },
            {
                'name': 'G.I.D.C Garden',
                'category': 'garden',
                'note': 'Green space with open fields and walking paths in the industrial area, favored by locals and children.',
            },
            {
                'name': 'Vanganga Lake',
                'category': 'nature',
                'note': 'Serene lake near Vapi with island gardens, paddle boats and flowerbeds.',
            },
            {
                'name': 'Daman Ganga Riverbank',
                'category': 'nature',
                'note': 'Picturesque riverside spot that draws sightseers year-round.',
            },
            {
                'name': 'Daman Beaches (nearby)',
                'category': 'beach',
                'note': 'Golden-sand beaches with palm groves and historical monuments, a short drive from Vapi.',
            },
            {
                'name': 'Udvada Atash Behram (nearby)',
                'category': 'religious',
                'note': 'Sacred Zoroastrian fire temple, a major Parsi pilgrimage site near Vapi.',
            },
        ],
        'food': [
            {
                'dish': 'Undhiu',
                'where': 'local homes and Gujarati thali restaurants',
                'note': 'Spicy mixed-vegetable dish traditionally slow-cooked in an earthenware pot.',
            },
            {
                'dish': 'Gujarati Thali',
                'where': 'most restaurants and hotels in Vapi',
                'note': 'Full vegetarian meal with rice, dal, vegetables, pickle, chutney, papad and buttermilk.',
            },
            {
                'dish': 'Thepla',
                'where': 'homes and local eateries',
                'note': 'Common everyday flatbread, a Vapi/Gujarati staple.',
            },
            {
                'dish': 'Street food / chaats and farsan',
                'where': 'Khau Galli and local street stalls',
                'note': 'Spicy chaats and crispy farsan reflecting typical Gujarati street food flavors.',
            },
        ],
    },
    'umreth': {
        'character': 'Umreth is a small heritage town in Anand district, Gujarat, known as the "Silk City of Charotar" for its silk sarees and old Pol (traditional cluster housing) culture.',
        'landmarks': [
            {
                'name': 'Shree Ram Mandir',
                'category': 'religious',
                'note': 'Considered the most famous temple in Umreth town.',
            },
            {
                'name': 'Shree Giriraj Dham',
                'category': 'religious',
                'note': 'Well-known Hindu temple located in Umreth.',
            },
            {
                'name': 'Umreth Jain Temple (Shri Shankheshwar Parshwanath)',
                'category': 'religious',
                'note': 'Jain temple dedicated to Tirthankara Adinatha, noted for intricate carvings and murals.',
            },
            {
                'name': 'Umreth Municipal Garden',
                'category': 'garden',
                'note': 'Popular public park with walking paths and playgrounds.',
            },
            {
                'name': 'Jivraj Mehta Udyan',
                'category': 'garden',
                'note': 'Large park with lush greenery and jogging tracks.',
            },
            {
                'name': 'Kavi Kunjan Mehta Garden',
                'category': 'garden',
                'note': 'Memorial garden honoring poet Kavi Kunjan Mehta, known for Hindi/Gujarati literary contributions.',
            },
            {
                'name': 'Historic Pol neighborhoods',
                'category': 'heritage',
                'note': "Traditional clustered housing lanes reflecting Umreth's old Charotar-region heritage.",
            },
        ],
        'food': [
            {
                'dish': 'Gujarati thali (dal, khichdi, sabzi, dhokla)',
                'where': 'local eateries in town',
                'note': 'Standard vegetarian Gujarati fare emphasized in the area.',
            },
            {
                'dish': 'Kachori, samosa, chaat',
                'where': 'street food vendors',
                'note': 'Common local street snacks in Umreth.',
            },
            {
                'dish': 'Basundi and shrikhand',
                'where': 'local sweet shops',
                'note': 'Regional milk-based sweets popular in the Charotar/Anand region.',
            },
            {
                'dish': 'Fresh milk and dairy products',
                'where': 'Anand district (Umreth area)',
                'note': "Umreth sits in Anand district, India's 'Milk Capital', known for dairy productivity.",
            },
        ],
    },
    'upleta': {
        'character': "Upleta is a modest market town in Rajkot district's Saurashtra region, known for its temples and traditional handicrafts like pottery and handloom weaving rather than major tourist draws.",
        'landmarks': [
            {
                'name': 'Khodiyar Mata Temple',
                'category': 'religious',
                'note': 'A revered pilgrimage site with intricate carvings and vibrant colors, especially busy during festivals.',
            },
            {
                'name': 'Swaminarayan Temple',
                'category': 'religious',
                'note': 'Traditional Indian architecture with detailed carvings and frescoes; best visited October to March.',
            },
            {
                'name': 'Bhadrakali Temple',
                'category': 'religious',
                'note': 'A revered Hindu shrine dedicated to Goddess Bhadrakali, blending traditional and modern architectural styles.',
            },
            {
                'name': 'Triloknath Mahadev Temple',
                'category': 'religious',
                'note': 'A Shiva temple on Kolki Road drawing residents and pilgrims for daily rituals.',
            },
            {
                'name': 'Upleta Jain Tirth',
                'category': 'religious',
                'note': 'A pilgrimage site with ornate Jain architecture, active during festivals like Paryushana.',
            },
            {
                'name': 'Upleta Lake',
                'category': 'nature',
                'note': 'A serene lakeside spot popular for a peaceful stroll or picnic.',
            },
        ],
        'food': [
            {
                'dish': 'Traditional Kathiawadi/Saurashtra fare',
                'where': 'Local eateries around town',
                'note': 'Search coverage for Upleta-specific dishes was thin; the town is noted more for handicrafts (pottery, handloom weaving) than a distinct food specialty, so no invented dishes are listed here.',
            },
        ],
    },
    'veraval': {
        'character': 'A coastal pilgrimage and fishing town in Gujarat, famed for the Somnath Jyotirlinga temple and its bustling fishing harbor.',
        'landmarks': [
            {
                'name': 'Somnath Temple',
                'category': 'religious',
                'note': 'One of the twelve Jyotirlingas, a major Shiva pilgrimage site with a renowned evening aarti',
            },
            {
                'name': 'Veraval Beach',
                'category': 'beach',
                'note': 'Coastal spot for sea breeze and sunset views near the town',
            },
            {
                'name': 'Bhalka Tirth',
                'category': 'religious',
                'note': "Sacred site believed to be where Lord Krishna was struck by a hunter's arrow",
            },
            {
                'name': 'Triveni Sangam',
                'category': 'religious',
                'note': 'Sacred confluence of three rivers, a spot for pilgrims and tourists',
            },
            {
                'name': 'Prabhas Patan Museum',
                'category': 'museum',
                'note': "Houses ancient artifacts, sculptures, and inscriptions from the region's history",
            },
            {
                'name': 'Veraval Fishing Harbor',
                'category': 'attraction',
                'note': "Bustling local harbor showcasing the town's active fishing industry",
            },
        ],
        'food': [
            {
                'dish': 'Fresh seafood (fish curry, fried fish)',
                'where': 'Local eateries near the harbor',
                'note': 'Coastal town known for daily catch of fish and prawns prepared as curries and fry',
            },
            {
                'dish': 'Prawns Masala',
                'where': 'Local seafood eateries',
                'note': "Popular spiced prawn preparation reflecting the town's fishing heritage",
            },
            {
                'dish': 'Dhokla, Thepla, Fafda',
                'where': 'Local dhabas and Gujarati eateries',
                'note': 'Classic Gujarati snacks widely available alongside the coastal fare',
            },
        ],
    },
    'visnagar': {
        'character': 'A quaint temple town in Mehsana district of Gujarat, known chiefly for its Swaminarayan Temple and traditional Gujarati vegetarian cuisine.',
        'landmarks': [
            {
                'name': 'Swaminarayan Temple',
                'category': 'religious',
                'note': 'Main attraction of Visnagar, known for its architecture and lively festival celebrations like Janmashtami and Diwali',
            },
        ],
        'food': [
            {
                'dish': 'Dhokla',
                'where': 'local eateries',
                'note': 'Popular traditional Gujarati steamed snack served in the town',
            },
            {
                'dish': 'Khandvi',
                'where': 'local eateries',
                'note': 'Classic Gujarati savory rolled snack',
            },
            {
                'dish': 'Shrikhand',
                'where': 'local eateries',
                'note': 'Sweet yogurt-based dessert popular in the region',
            },
            {
                'dish': 'Pani puri / samosas',
                'where': 'street food stalls',
                'note': 'Common street snacks found around town',
            },
        ],
    },
    'viramgam': {
        'character': 'A small historic railway-junction town in Ahmedabad district known for its fort, surrounding lakes, and proximity to Nal Sarovar Bird Sanctuary and Lothal.',
        'landmarks': [
            {
                'name': 'Viramgam Fort',
                'category': 'heritage',
                'note': "Old fort in the town, one of Viramgam's notable historic sites",
            },
            {
                'name': 'Town Lakes (three lakes surrounding Viramgam)',
                'category': 'nature',
                'note': 'Viramgam is surrounded by three important lakes that are locally popular',
            },
            {
                'name': 'Nal Sarovar Bird Sanctuary (nearby)',
                'category': 'wildlife',
                'note': 'Well-known bird sanctuary accessible by road from Viramgam',
            },
            {
                'name': 'Lothal (nearby)',
                'category': 'heritage',
                'note': 'Ancient Indus Valley site reachable from Viramgam',
            },
        ],
        'food': [
            {
                'dish': 'Dhokla',
                'where': 'local eateries in Viramgam',
                'note': 'Steamed fermented rice and chickpea flour snack, a staple Gujarati dish available locally',
            },
            {
                'dish': 'Thepla',
                'where': 'local eateries in Viramgam',
                'note': 'Spiced flatbread made with fenugreek leaves, yogurt and whole wheat flour',
            },
            {
                'dish': 'Khandvi',
                'where': 'local eateries in Viramgam',
                'note': 'Rolled gram-flour and yogurt snack seasoned with mustard seeds and coconut',
            },
        ],
    },
    'vijapur': {
        'character': 'A historical Jain and Hindu pilgrimage town in Mehsana district, Gujarat, known for its temples and its association with Jain saint Buddhisagar Suri.',
        'landmarks': [
            {
                'name': 'Vijapur Fort',
                'category': 'heritage',
                'note': "Historical fort that stands as a landmark of the town's past.",
            },
            {
                'name': 'Buddhisagar Suri Samadhi',
                'category': 'religious',
                'note': 'Memorial to the noted Jain priest and scholar Buddhisagar Suri (1874-1925), located behind the government guest house at Vijapur.',
            },
            {
                'name': 'Swaminarayan Temple, Vijapur',
                'category': 'religious',
                'note': "One of the town's ancient temples.",
            },
            {
                'name': 'Jasmalnathji Mahadev Temple',
                'category': 'religious',
                'note': 'Ancient Shiva temple built in the 10th century, located in nearby Asoda village in Vijapur Taluka.',
            },
        ],
        'food': [
            {
                'dish': 'Dhokla',
                'where': 'local sweet shops and eateries',
                'note': 'Popular steamed gram-flour snack found across Mehsana district towns like Vijapur, as with the rest of Gujarat.',
            },
            {
                'dish': 'Thepla',
                'where': 'local homes and eateries',
                'note': 'Common Gujarati flatbread snack/breakfast item typical of the region.',
            },
        ],
    },
    'vyara': {
        'character': 'Vyara is the headquarters of Tapi district in south Gujarat, a lesser-known destination amid forests, hills and rivers, known for its 15th-century fort and nearby waterfalls/dams.',
        'landmarks': [
            {
                'name': 'Vyara Fort',
                'category': 'heritage',
                'note': "15th-century fort/stronghold, a historical landmark reflecting the region's heritage",
            },
            {
                'name': 'Shri Swaminarayan Mandir',
                'category': 'religious',
                'note': 'Temple dedicated to Lord Swaminarayan noted for intricate carvings',
            },
            {
                'name': 'Jalvatika Garden',
                'category': 'garden',
                'note': 'Local garden spot popular with visitors',
            },
            {
                'name': 'Mayadevi Waterfall Temple',
                'category': 'religious',
                'note': 'Temple site associated with a waterfall',
            },
            {
                'name': 'Kakrapar Dam',
                'category': 'nature',
                'note': 'Popular nearby dam and viewpoint',
            },
            {
                'name': 'Ukai Dam',
                'category': 'nature',
                'note': 'Large dam near Vyara, a common day-trip attraction',
            },
            {
                'name': 'Gaytri Mandir',
                'category': 'religious',
                'note': "Local temple listed among Vyara's attractions",
            },
        ],
        'food': [
            {
                'dish': 'Undhiyu',
                'where': 'General Surat/south Gujarat region',
                'note': 'Popular winter mixed-vegetable dish common across south Gujarat, not documented as Vyara-specific by sources found',
            },
            {
                'dish': 'Dhokla / Khaman',
                'where': 'General Gujarat',
                'note': 'Widely available Gujarati staple; searches did not surface a dish unique to Vyara itself',
            },
        ],
    },
    'wadhwan': {
        'character': 'Wadhwan is a historic princely-state town in Surendranagar district known as the "two-step wells city" and a center of Jain heritage and traditional wooden-toy craft.',
        'landmarks': [
            {
                'name': 'Ranakdevi Temple',
                'category': 'religious',
                'note': 'Temple in southern Wadhwan built near the royal cremation grounds, commemorating Ranakdevi who committed sati.',
            },
            {
                'name': 'Wagheshwari Devi Temple',
                'category': 'religious',
                'note': 'Prominent temple famous for its Navratri celebrations, where only men perform Garba.',
            },
            {
                'name': 'Madhu Vav',
                'category': 'heritage',
                'note': "Historic stepwell showcasing the town's noted subterranean architecture.",
            },
            {
                'name': 'Ganga Vav',
                'category': 'heritage',
                'note': "One of the two well-known stepwells that give Wadhwan its nickname, the 'two-step wells city'.",
            },
            {
                'name': 'Vadwala Temple',
                'category': 'religious',
                'note': 'A roughly 450-year-old temple in the town.',
            },
            {
                'name': 'Hawamahal Wadhwan',
                'category': 'heritage',
                'note': 'Historic palace-era structure listed as a tourist site by the Surendranagar district administration.',
            },
            {
                'name': 'Swami Narayan Temple',
                'category': 'religious',
                'note': "One of the town's prominent religious attractions.",
            },
            {
                'name': 'Nal Sarovar Bird Sanctuary',
                'category': 'wildlife',
                'note': 'Large migratory bird sanctuary (pelicans, flamingos, storks) located about 40 km from Wadhwan.',
            },
        ],
        'food': [],
    },
    'wankaner': {
        'character': "Wankaner is a small royal-heritage town in Gujarat's Morbi district, best known for its dramatic Ranjit Vilas Palace overlooking the town from Gadhio Hills.",
        'landmarks': [
            {
                'name': 'Ranjit Vilas Palace',
                'category': 'heritage',
                'note': 'Royal palace on Gadhio Hills blending Victorian, Mughal, Gothic and Dutch styles, with a watchtower and a museum of paintings, armaments and vintage royal cars',
            },
            {
                'name': 'Swaminarayan Temple',
                'category': 'religious',
                'note': 'Popular Hindu temple in Wankaner town',
            },
            {
                'name': 'Gayatri Temple',
                'category': 'religious',
                'note': 'Well-known local temple visited by pilgrims',
            },
            {
                'name': 'Hazrat Shah Bava Dargah',
                'category': 'religious',
                'note': "Shrine of Shahbava, one of Wankaner's founders, revered by devotees of all faiths",
            },
            {
                'name': 'Nagabava Temple',
                'category': 'religious',
                'note': 'Temple enshrining Nagabava, another town founder from about 500 years ago, known for its architecture',
            },
            {
                'name': 'Machhu Dam',
                'category': 'nature',
                'note': 'Dam on the Machhu river popular for scenic views and day picnics',
            },
            {
                'name': 'Wankaner Lake',
                'category': 'nature',
                'note': 'Tranquil lake spot popular with nature lovers',
            },
            {
                'name': 'Mitticool',
                'category': 'attraction',
                'note': 'Well-known clay-products workshop/showroom (coolers, fridges, cookers) started by potter Mansukh Prajapati',
            },
        ],
        'food': [
            {
                'dish': 'Ghari',
                'where': 'Wankaner',
                'note': 'Sweet made from chickpea flour, sugar and ghee, said to have originated in the town',
            },
            {
                'dish': 'Mohanthal',
                'where': 'Wankaner',
                'note': 'Traditional Gujarati gram-flour sweet, a local favorite',
            },
            {
                'dish': 'Dabeli',
                'where': 'local street stalls',
                'note': 'Street snack of spiced potato filling, chutneys, peanuts and sev in a bun',
            },
            {
                'dish': 'Fafda-Jalebi',
                'where': 'local street stalls',
                'note': 'Crispy gram-flour snack usually paired with jalebi',
            },
            {
                'dish': 'Khandvi',
                'where': 'local sweet/snack shops',
                'note': 'Rolled gram-flour and yogurt snack garnished with coconut and coriander',
            },
            {
                'dish': 'Kathiyawadi thali (bajra rotla, sev tameta shaak, kadhi)',
                'where': 'local eateries',
                'note': 'Traditional Kathiyawadi regional fare common in the area',
            },
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
