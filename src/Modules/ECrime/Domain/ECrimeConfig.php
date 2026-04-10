<?php
declare(strict_types=1);

namespace Netmafia\Modules\ECrime\Domain;

class ECrimeConfig
{
    public const SCAM_COOLDOWN_MINUTES = 9;

    public const SCAM_TYPES = [
        1 => [
            'id' => 1,
            'name' => 'Hamis apróhirdetés készítése',
            'base_success_chance' => 30, // 0 db-nál
            'max_success_chance' => 99,
            // 1695 db-nál pontosan 95% kell legyen: (95 - 30) / 1695 = 0.038348
            'k_factor' => 8.74,          // log-alapú: min(99, 30 + 8.74*ln(1+n)) → 95% @1695
            'xp_min' => 44,
            'xp_max' => 62,
            'money_min' => 100,
            'money_max' => 200,
            'energy_min' => 7,
            'energy_max' => 12,
            'success_messages' => [
                'Felraktál egy "alig használt, újszerű" csúcsmobilt a netre töredékáron. Egy kapzsi balek azonnal ráharapott és előre utalt. A pénz a fedőszámládon landolt, a hirdetés és a profilod pedig már köddé is vált. Szép, tiszta munka.',
                'A nem létező játékkonzol hirdetésed telitalálat volt. A vevő annyira félt, hogy lemarad az üzletről, hogy azonnal elküldte a foglalót. Te zsebre tetted a lóvét, ő meg várhatja a postást ítéletnapig.'
            ],
            'fail_messages' => [
                'Az oldal algoritmusa pillanatok alatt kiszúrta a netről lopott képeket, és azonnal tiltotta a fiókodat. Még szerencse, hogy VPN mögül próbálkoztál, különben már kopogtatnának az ajtódon.',
                'A vevő túlságosan is óvatos volt. Ragaszkodott a személyes átvételhez, és amikor próbáltad terelni, gyanút fogott és feljelentéssel fenyegetőzött. Jobb, ha hanyagolod ezt a profilt egy darabig, mielőtt megégeted magad.'
            ]
        ],
        2 => [
            'id' => 2,
            'name' => 'Hírhedt bűnszervezet nevében telefonálás',
            'base_success_chance' => 21, // Példa induló (hogy 68 legyen 1695-nél)
            'max_success_chance' => 99,
            // 1695 db-nál pontosan 68% kell legyen: (68 - 21) / 1695 = 0.027728
            'k_factor' => 6.32,          // log-alapú: min(99, 21 + 6.32*ln(1+n)) → 68% @1695

            'xp_min' => 52,
            'xp_max' => 77,
            'money_min' => 110,
            'money_max' => 205,
            'energy_min' => 7,
            'energy_max' => 12,
            'success_messages' => [
                'Az öreglány hangja remegett a vonal másik végén, amikor közölted, hogy az unokája csúnya adósságba verte magát a Családnál. Tökéletesen hoztad a könyörtelen behajtót. A strómanod már úton is van a borítékért.',
                'A célszemély annyira bepánikolt a fenyegetéseidtől, hogy minden kérdés nélkül utalta a "szabadulópénzt" a lenyomozhatatlan kriptotárcádba. Még sírt is a telefonba. Könnyű pénz volt.'
            ],
            'fail_messages' => [
                'Hatalmasat mellényúltál. A hívott fél nem egy ijedt rokon volt, hanem egy hidegvérű figura, aki rögtön elkezdett keresztkérdéseket feltenni. Mikor rákérdezett a hívásazonosítódra, gyorsan ki kellett nyomnod.',
                'A hapsi csak nevetett a fenyegetéseden. Közölte, hogy a fia épp mellette ül a kanapén, majd elküldött melegebb éghajlatra. Ezt a kört csúnyán elbuktad, a hírneved is csorbát szenvedett.'
            ]
        ],
        3 => [
            'id' => 3,
            'name' => 'Társkereső oldalon álprofillal pénz kérése',
            'base_success_chance' => 12,
            'max_success_chance' => 99,
            // 1695 db-nál pontosan 39% kell legyen: (39 - 12) / 1695 = 0.015929
            'k_factor' => 3.63,          // log-alapú: min(99, 12 + 3.63*ln(1+n)) → 39% @1695

            'xp_min' => 57,
            'xp_max' => 112,
            'money_min' => 130,
            'money_max' => 221,
            'energy_min' => 7,
            'energy_max' => 12,
            'success_messages' => [
                'Három heti kemény érzelmi manipuláció beérett. A netes "szerelmed" elhitte a mesét a sürgős műtétről, és egy vaskos összeget utalt a számládra. Csak a klaviatúrát kellett koptatnod a milliókért.',
                'A gazdag, de magányos áldozatod teljesen beleesett a gondosan felépített álprofilodba. Amikor bedobtad, hogy "repülőjegyre" kéne egy kis gyors kölcsön, hogy végre találkozzatok, gondolkodás nélkül perkált.'
            ],
            'fail_messages' => [
                'Túlságosan mohó voltál. Amikor a harmadik találkozót is lemondtad egy "hirtelen jött tragédia" miatt, az áldozat rákeresett a fotóidra, és rájött a csalásra. A profilodat tiltották, a befektetett időd pedig kárba veszett.',
                'A platform moderátorai kiszűrték a fiókodat a gyanús üzenetküldési minták alapján, még mielőtt a pénzkérésig eljutottál volna. Hiába etetted napokig a halat, ha a háló elszakadt.'
            ]
        ],
        4 => [
            'id' => 4,
            'name' => 'Adathalász SMS küldése befektetési lehetőségről (webszerver bérlés szükséges)',
            'base_success_chance' => 8,
            'max_success_chance' => 99,
            // 1695 db-nál pontosan 26% kell legyen: (26 - 8) / 1695 = 0.010619
            'k_factor' => 2.42,          // log-alapú: min(99, 8 + 2.42*ln(1+n)) → 26% @1695

            'xp_min' => 70,
            'xp_max' => 230,
            'money_min' => 400,
            'money_max' => 1200,
            'energy_min' => 7,
            'energy_max' => 12,
            'success_messages' => [
                'A tömeges SMS-kampányod telitalálat! Pár tucat naiv áldozat rákattintott a linkre, és szépen megadták a bankkártyaadataikat a klónozott fizetési oldalon. A szkripted már szívja is le a számláikat.',
                'A "vissza nem térő VIP kripto-befektetés" szöveg bevált. Egy igazi nagypályás balek sétált be a csapdába, és komoly tőkét hagyott a fiktív portálodon, abban a hitben, hogy holnapra milliomos lesz.'
            ],
            'fail_messages' => [
                'A mobilszolgáltatók spamszűrője frissült, és az üzeneteid 99%-a fennakadt a rostán. Aki megkapta, az se dőlt be az olcsó trükknek. Csak a sávszélességet és az SMS-költséget buktad.',
                'Valaki gyorsan bejelentette a linkelt oldaladat, a böngészők feketelistára tették, és hatalmas piros figyelmeztetés fogadta a kattintókat. Egy vasat sem kerestél vele, és új domaint kell venned a következő akcióhoz.'
            ]
        ]
    ];
}
