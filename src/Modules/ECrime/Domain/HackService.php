<?php
declare(strict_types=1);

namespace Netmafia\Modules\ECrime\Domain;

use Netmafia\Shared\Domain\ValueObjects\UserId;
use Netmafia\Modules\Health\Domain\HealthService;
use Netmafia\Modules\Xp\Domain\XpService;
use Netmafia\Modules\Item\Domain\BuffService;
use Doctrine\DBAL\Connection;

class HackService
{
    private Connection $db;
    private HealthService $healthService;
    private XpService $xpService;
    private BuffService $buffService;

    // Success messages
    private const SUCCESS_MESSAGES = [
        "Sikeresen megírtál néhány új kódsort a vírusodhoz, ami így +0.5%-kal lett fejlettebb. A programozás közben jobban átláttad a hálózatok működését, így a hackerkészségeid is javultak.",
        "A vírusod algoritmusának finomhangolása sikeres volt, a fejlettségi szint +0.5%-kal nőtt. A munka során új architektúrákat és biztonsági réseket tanulmányoztál, ami tovább mélyítette a hacker tudásodat.",
        "Vírus továbbfejlesztve: +0.5%. A programozási munkálatok alatt értékes tapasztalatokat gyűjtöttél a rendszerek felépítéséről, ezáltal a hacker szinted is növekedett.",
        "Órákat töltöttél a kód optimalizálásával és a hibák javításával, aminek meglett az eredménye: a vírusod +0.5%-ot lépett előre. A folyamat során szerzett új rendszerismereteknek köszönhetően a hacker tapasztalatod is gyarapodott.",
        "Új rutinokat integráltál a szoftveredbe, így a vírus +0.5%-kal fejlettebb lett. A különböző operációs rendszerek tanulmányozása közben értékes hacker tapasztalatokra is szert tettél."
    ];

    public function __construct(Connection $db, HealthService $healthService, XpService $xpService, BuffService $buffService)
    {
        $this->db = $db;
        $this->healthService = $healthService;
        $this->xpService = $xpService;
        $this->buffService = $buffService;
    }

    public function developVirus(UserId $userId): array
    {
        $id = $userId->id();

        // 1. Get current user stats using a FOR UPDATE lock
        $this->db->beginTransaction();
        try {
            $user = $this->db->fetchAssociative(
                "SELECT virus_progress, virus_dev_cooldown_until, has_peripherals FROM users WHERE id = ? FOR UPDATE",
                [$id]
            );

            if (!$user) {
                $this->db->rollBack();
                throw new \RuntimeException('Játékos nem található.');
            }

            // 2. Check Cooldown
            if ($user['virus_dev_cooldown_until']) {
                $cdTime = new \DateTimeImmutable($user['virus_dev_cooldown_until'], new \DateTimeZone('UTC'));
                $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                if ($cdTime > $now) {
                    $this->db->rollBack();
                    throw new \Exception('Még várnod kell a következő fejlesztésig!');
                }
            }

            // 3. Skálázódó nehézség a vírus fejlettségi szintje alapján
            $tier = $this->getDevTier((float)$user['virus_progress']);
            $roll = random_int(1, 100);
            $success = ($roll <= $tier['success_chance']);

            $nowStr = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            
            if ($success) {
                // SUCCESS LOGIC
                // 13 min base; -30% with peripherals; then apply buff cooldown_reduction
                $baseMinutes = !empty($user['has_peripherals']) ? 9 : 13;
                $reductionPercent = $this->buffService->getActiveBonus($id, 'cooldown_reduction', 'hacking');
                $cdSeconds = (int)($baseMinutes * 60 * (1 - $reductionPercent / 100));
                $cooldownEndsAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                                    ->modify("+{$cdSeconds} seconds")
                                    ->format('Y-m-d H:i:s');
                
                // Fejlettségtől függő lépésméret
                $newProgress = min(100.00, (float)$user['virus_progress'] + $tier['gain']);
                
                $this->db->executeStatement(
                    "UPDATE users 
                     SET virus_progress = ?, 
                         virus_dev_cooldown_until = ?,
                         last_virus_development_at = ? 
                     WHERE id = ?",
                    [$newProgress, $cooldownEndsAt, $nowStr, $id]
                );

                $xpReward = random_int(5, 12);
                $this->xpService->addXp($userId, $xpReward, 'Hacking - Fejlesztés');

                $this->db->commit();

                // Select a random success message
                $messageIndex = random_int(0, count(self::SUCCESS_MESSAGES) - 1);
                
                return [
                    'success' => true,
                    'message' => self::SUCCESS_MESSAGES[$messageIndex]
                ];

            } else {
                // FAILURE LOGIC
                // 5 min base; -30% peripherals; then apply buff cooldown_reduction
                $baseMinutes = !empty($user['has_peripherals']) ? 3.5 : 5;
                $reductionPercent = $this->buffService->getActiveBonus($id, 'cooldown_reduction', 'hacking');
                $cdSeconds = (int)($baseMinutes * 60 * (1 - $reductionPercent / 100));
                $cooldownEndsAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                                    ->modify("+{$cdSeconds} seconds")
                                    ->format('Y-m-d H:i:s');

                $this->db->executeStatement(
                    "UPDATE users SET virus_dev_cooldown_until = ? WHERE id = ?",
                    [$cooldownEndsAt, $id]
                );

                $this->db->commit();

                // Apply health damage (requires its own transaction/lock in HealthService)
                $damage = random_int(3, 7);
                try {
                    // Requires user id as an integer in HealthService? No, it takes UserId object.
                    $this->healthService->useEnergy(UserId::of($id), $damage, 'hackelés_fejlesztési_hiba');
                } catch (\Throwable $e) {
                    // Ignore if error occurs during damage (e.g. not enough energy)
                }

                return [
                    'success' => false,
                    'message' => 'Sajnos nem sikerült a fejlesztés. A számítógép túlmelegedett és a stressztől vesztettél ' . $damage . ' energiát.'
                ];
            }

        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public const DISTRIBUTE_METHODS = [
        1 => [
            'name' => 'Nagyvállalatok',
            'base_chance' => 12,
            'xp_min' => 3, 'xp_max' => 6,
            'zombie_min' => 1, 'zombie_max' => 3,
            'success_messages' => [
                "Sikeres behatolás! Egy nagyvállalat gyanútlan alkalmazottja megnyitotta a manipulált e-mailedet, így a tűzfalat megkerülve azonnal belső hozzáférést szereztél. A vírusod észrevétlenül települt a cég hálózatán lévő eszközökre.",
                "A hackelés sikeres! Felfedeztél és kihasználtál egy kritikus sebezhetőséget egy közintézmény elavult szerverén. A rendszergazdák semmit sem vettek észre, miközben a vírusod csendben feltelepült a csatlakoztatott munkaállomásokra.",
                "Kiváló munka! Miután feltörted a vállalat központi adatbázisát, a vírusod láncreakciószerűen kezdett el terjedni a belső hálózaton. Pillanatok alatt több számítógépet és eszközt fertőztél meg anélkül, hogy a riasztók megszólaltak volna.",
                "Bejutottál! Egy feketepiacon szerzett, kiszivárgott adminisztrátori jelszó segítségével hátsó ajtót nyitottál egy multinacionális cég rendszerébe. A vírus telepítése zökkenőmentesen lezajlott, a gépek feletti irányítás a te kezedben van.",
                "Tökéletes akció! A behatolás teljesen nyom nélkül történt. Sikeresen megkerülted a közintézmény védelmi rendszereit, és a vírusod a háttérben futó folyamatok közé rejtőzve települt az eszközökre. A fertőzött gépek mostantól rád várnak."
            ],
            'error_messages' => [
                "Kudarc! A vállalat új generációs végpontvédelmi rendszere gyanús viselkedést észlelt, és karanténba zárta a vírusodat, mielőtt a telepítés egyáltalán megkezdődhetett volna. A behatolási kísérleted elbukott.",
                "A behatolás meghiúsult! Bár a tűzfalon átjutottál, egy éber rendszergazda észrevette a hálózatba irányuló szokatlan adatforgalmat. Azonnal lekapcsolták az érintett szervereket, így a vírusod nem tudott szétterjedni.",
                "Csapdába léptél! A rendszer, amit látszólag olyan könnyen feltörtél, valójában egy kiberbiztonsági \"mézesmadzag\" (honeypot) volt, amit pont a hackerek elfogására hoztak létre. Épphogy sikerült eltüntetned a nyomaidat.",
                "Hiba a futtatás során! A kiszemelt közintézmény váratlanul frissítette a szoftvereit a hétvégén. A sebezhetőség, amit ki akartál használni, már be van foltozva, így a rendszer azonnal eldobta a kapcsolódási kísérletet.",
                "Sikertelen akció! A vírusod sikeresen bejutott a hálózatba, de egy váratlan szerverhiba miatt a belső kommunikáció megszakadt. A kódod nem tudott megfelelően lefutni és települni, az egészet elölről kell majd kezdened."
            ]
        ],
        2 => [
            'name' => 'Szoftverterjesztő',
            'base_chance' => 5,
            'xp_min' => 4, 'xp_max' => 12,
            'zombie_min' => 2, 'zombie_max' => 4,
            'success_messages' => [
                "Sikeres fertőzés! Feltörtél egy népszerű szoftveres portált, és a vírusodat hozzácsomagoltad egy gyakran letöltött segédprogramhoz. A gyanútlan felhasználók éppen telepítik a kódodat a saját gépeikre.",
                "Tökéletes álcázás! Sikerült kicselezned a letöltőoldal ellenőrző mechanizmusait, és a módosított telepítőfájlt a hivatalos digitális aláírással láttad el. A letöltők teljesen megbíznak a fájlban.",
                "Telitalálat! Egy kisebb szoftverfejlesztő cég szerverére bejutva a vírusodat a programjuk automatikus frissítőcsomagjába rejtetted. Ahogy a szoftver a háttérben frissíti magát a klienseknél, csendben a kódod is települ.",
                "Vírus terjesztés folyamatban! Egy népszerű, feltört játékokat kínáló oldal adatbázisába jutottál be. Mivel a felhasználók eleve kikapcsolják a vírusirtójukat a telepítéshez, a módosított fájljaid akadálytalanul futnak le.",
                "Csendes siker! Kicserélted a telepítőfájlt, és olyan ügyesen tüntetted el a nyomaidat a szerver logjaiban, hogy az üzemeltetők nem fogtak gyanút. A fertőzött eszközök száma stabilan növekszik."
            ],
            'error_messages' => [
                "Kudarc! Sikeresen kicserélted a telepítőfájlt, de az oldal automatikus fájlintegritás-ellenőrző rendszere észlelte, hogy a telepítő hash-értéke megváltozott. A rendszert azonnal leállították, a fertőzött fájlt törölték.",
                "A feltöltés meghiúsult! Amikor megpróbáltad felülírni az eredeti szoftvert a trójai verzióval, a szerveroldali végpontvédelem felismerte a kártékony kódmintát a fájlban, és azonnal blokkolta az IP-címedet.",
                "Lelepleződtél! nem tudtad megfelelően meghamisítani a fejlesztő digitális aláírását. Az operációs rendszerek beépített védelme (SmartScreen) azonnal veszélyesnek jelölte a letöltött fájlt, így nem futtatták azt.",
                "Rajtakaptak! Az oldal egyik adminisztrátora éppen online volt, és felfigyelt az adatbázisban történő jogosulatlan FTP forgalomra. Gyorsabb volt nálad: kizárt a rendszerből és visszaállította a biztonsági mentést.",
                "Melléfogás! Sok energiát fektettél az oldal feltörésébe, de kiderült, hogy a szoftver, amit megfertőztél, már egy elavult, senki által nem letöltött projekt. Egyetlen új eszközt sem sikerült a zombihálózathoz csatolni."
            ]
        ],
        3 => [
            'name' => 'IP Szkennelés',
            'base_chance' => 4,
            'xp_min' => 6, 'xp_max' => 15,
            'zombie_min' => 6, 'zombie_max' => 13,
            'requires_webserver' => true,
            'success_messages' => [
                "Siker! A tömeges szkennelés rátalált rengeteg védtelen okoseszközre (routerekre, kamerákra), amelyeken a tulajdonosok sosem változtatták meg a gyári 'admin/admin' jelszavakat. A vírusod percek alatt beépült.",
                "Találat! A bérelt szerverparkod fáradhatatlanul dolgozott, és ráakadt egy elavult operációs rendszereket futtató hálózati szegmensre. A foltozatlan sebezhetőségeket kihasználva a vírusod automatikusan feltelepült.",
                "Sikeres akció! A szkripted nyitott, védelem nélküli portokra és rosszul konfigurált adatbázisokra bukkant a weben. Az automatizált fertőzési rutin hiba nélkül lefutott, jelentős számú új 'zombit' csatlakoztatva.",
                "Kiváló hatékonyság! A szerverparkod hatalmas sávszélességének köszönhetően a szkennelés villámgyorsan lezajlott. Több tucat sebezhető eszközt azonosítottál és fertőztél meg egyszerre.",
                "Tökéletes időzítés! Egy nemrégiben nyilvánosságra hozott biztonsági rést keresve futtattad a szkennert. Számtalan olyan eszközt találtál, amit a rendszergazdák még nem értek rá frissíteni, így a vírus akadálytalanul települt."
            ],
            'error_messages' => [
                "Kudarc! A tömeges IP szkennelés túl nagy hálózati zajt csapott. A bérelt szerverparkod hosting szolgáltatója visszaélésre hivatkozva azonnal felfüggesztette a fiókodat és lekapcsolta a gépeidet. Az akció megszakadt.",
                "Csapdába estél! A szkennert egy kutatók által fenntartott 'kátránygödör' (tarpit) fogta meg. A szervereid a hamis IP-címek és a kamu kapcsolatok elemzésével pazarolták az időt, egyetlen valódi eszközt sem fertőztél meg.",
                "Letiltva! A célpontok tűzfalai és a nemzetközi fenyegetés-elemző rendszerek pillanatok alatt detektálták a szervereidről induló agresszív letapogatást. A szerverparkod IP-címei azonnal feketelistára kerültek.",
                "Sikertelen keresés! Bár a szervereid repesztettek, az exploit-készleted túlságosan elavult volt. Több ezer IP címet vizsgáltál meg, de az eszközök mindegyike védve volt a sebezhetőségek ellen. Az eredmény nulla.",
                "Rendszerösszeomlás! A tömeges letapogatás túl sok számítási kapacitást és memóriát emésztett fel, a feladatot futtató kódod memóriaszivárgás miatt kifagyott. A bérelt szervereid egyszerűen leálltak a művelet közepén."
            ]
        ]
    ];

    public function calculateDistributionChance(int $methodId, float $virusProgress, int $distAttempts): int
    {
        if (!isset(self::DISTRIBUTE_METHODS[$methodId])) {
            return 0;
        }

        $baseChance = self::DISTRIBUTE_METHODS[$methodId]['base_chance'];
        
        // Virus minőség bónusz: max +30% (olyan 100% progressnél)
        $virusBonus = max(0, min(100, $virusProgress) - 10) * 0.333;

        // Rutin bónusz: logaritmikusan nő max +30%-ig (kb 1000 sikeres terjesztés)
        $kFactor = 4.34;
        $skillBonus = $kFactor * log(1 + $distAttempts);

        $totalChance = round($baseChance + $virusBonus + $skillBonus);
        return (int) min(99, $totalChance); // Maximum 99% esély
    }

    public function distributeVirus(UserId $userId, int $methodId): array
    {
        if (!isset(self::DISTRIBUTE_METHODS[$methodId])) {
            throw new \Exception('Érvénytelen terjesztési módszer.');
        }

        $method = self::DISTRIBUTE_METHODS[$methodId];
        $id = $userId->id();

        $this->db->beginTransaction();
        try {
            $SELECT_COLS = "virus_progress, zombie_count, virus_dev_cooldown_until, virus_dist_attempts, webserver_expire_at, has_peripherals";
            $user = $this->db->fetchAssociative(
                "SELECT $SELECT_COLS FROM users WHERE id = ? FOR UPDATE",
                [$id]
            );

            if (!$user) {
                throw new \RuntimeException('Játékos nem található.');
            }

            if ((float)$user['virus_progress'] < 10) {
                throw new \Exception('A vírusod még nem elég fejlett a terjesztéshez (min 10%).');
            }

            if (isset($method['requires_webserver']) && $method['requires_webserver']) {
                $nowTs = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                if (empty($user['webserver_expire_at']) || new \DateTimeImmutable($user['webserver_expire_at'], new \DateTimeZone('UTC')) < $nowTs) {
                    throw new \Exception('Ehhez a módszerhez aktív webszerver bérlés szükséges!');
                }
            }

            // Check Cooldown
            if ($user['virus_dev_cooldown_until']) {
                $cdTime = new \DateTimeImmutable($user['virus_dev_cooldown_until'], new \DateTimeZone('UTC'));
                $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                if ($cdTime > $now) {
                    throw new \Exception('Még várnod kell a következő akcióig!');
                }
            }

            $chance = $this->calculateDistributionChance($methodId, (float)$user['virus_progress'], (int)$user['virus_dist_attempts']);
            $roll = random_int(1, 100);
            $success = ($roll <= $chance);

            if ($success) {
                // 13 min base; -30% peripherals; then apply buff cooldown_reduction
                $baseMinutes = !empty($user['has_peripherals']) ? 9 : 13;
                $reductionPercent = $this->buffService->getActiveBonus($id, 'cooldown_reduction', 'hacking');
                $cdSeconds = (int)($baseMinutes * 60 * (1 - $reductionPercent / 100));
                $cooldownEndsAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                                    ->modify("+{$cdSeconds} seconds")
                                    ->format('Y-m-d H:i:s');
                
                $zombieReward = random_int($method['zombie_min'], $method['zombie_max']);
                $newZombieCount = (int)$user['zombie_count'] + $zombieReward;
                $newDistAttempts = (int)$user['virus_dist_attempts'] + 1;

                $this->db->executeStatement(
                    "UPDATE users SET zombie_count = ?, virus_dist_attempts = ?, virus_dev_cooldown_until = ? WHERE id = ?",
                    [$newZombieCount, $newDistAttempts, $cooldownEndsAt, $id]
                );

                $xpReward = random_int($method['xp_min'], $method['xp_max']);
                $this->xpService->addXp($userId, $xpReward, 'Hacking - Terjesztés');

                $this->db->commit();

                // Select a random success message for the specific method
                $messages = $method['success_messages'] ?? ["Sikeres terjesztés!"];
                $msgIndex = random_int(0, count($messages) - 1);
                $narrative = $messages[$msgIndex];

                return [
                    'success' => true,
                    'message' => "{$narrative}<br><strong>Jutalom: +{$zombieReward} fertőzött gép, +{$xpReward} XP.</strong>"
                ];
            } else {
                // 5 min base; -30% peripherals; then apply buff cooldown_reduction
                $baseMinutes = !empty($user['has_peripherals']) ? 3.5 : 5;
                $reductionPercent = $this->buffService->getActiveBonus($id, 'cooldown_reduction', 'hacking');
                $cdSeconds = (int)($baseMinutes * 60 * (1 - $reductionPercent / 100));
                $cooldownEndsAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                                    ->modify("+{$cdSeconds} seconds")
                                    ->format('Y-m-d H:i:s');
                
                $this->db->executeStatement(
                    "UPDATE users SET virus_dev_cooldown_until = ? WHERE id = ?",
                    [$cooldownEndsAt, $id]
                );
                
                $this->db->commit();

                $damage = random_int(3, 7);
                try {
                    $this->healthService->useEnergy(UserId::of($id), $damage, 'hackelés_terjesztési_hiba');
                } catch (\Throwable $e) {}

                // Select a random error message for the specific method
                $messages = $method['error_messages'] ?? ["Nem sikerült a terjesztés."];
                $msgIndex = random_int(0, count($messages) - 1);
                $narrative = $messages[$msgIndex];

                return [
                    'success' => false,
                    'message' => "{$narrative}<br><strong>Büntetés: {$damage} energia levonva.</strong>"
                ];
            }

        } catch (\Exception $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            error_log("HackService distributeVirus error: " . $e->getMessage());
            throw new \Exception("Váratlan hiba történt a terjesztés során.");
        }
    }

    /**
     * Visszaadja az adott fejlettségi szinthez tartozó siker%-ot és lépésméretet.
     * 0→10%:   99% esély, +0.50%/fejlesztés
     * 10→25%:  85% esély, +0.40%/fejlesztés
     * 25→35%:  77% esély, +0.36%/fejlesztés  (átmeneti tier)
     * 35→50%:  70% esély, +0.33%/fejlesztés
     * 50→100%: 50% esély, +0.23%/fejlesztés
     */
    private function getDevTier(float $progress): array
    {
        if ($progress < 10) {
            return ['success_chance' => 99, 'gain' => 0.50];
        } elseif ($progress < 25) {
            return ['success_chance' => 85, 'gain' => 0.40];
        } elseif ($progress < 35) {
            return ['success_chance' => 77, 'gain' => 0.36];
        } elseif ($progress < 50) {
            return ['success_chance' => 70, 'gain' => 0.33];
        } else {
            return ['success_chance' => 50, 'gain' => 0.23];
        }
    }

    /**
     * Lazy decay: meghívandó minden alkalommal, amikor a játékos felkeresi az E-Crime oldalt.
     * Kiszámolja, hány teljes nap telt el az utolsó fejlesztés óta, és minden napra
     * 0.2%-ot von le a virus_progress-ből (minimum 0%).
     * Visszaadja a ténylegesen levont százalékot (0.0 ha nem volt decay).
     */
    public function applyLazyDecay(UserId $userId): float
    {
        $id = $userId->id();

        $user = $this->db->fetchAssociative(
            "SELECT virus_progress, last_virus_development_at FROM users WHERE id = ?",
            [$id]
        );

        if (!$user || (float)$user['virus_progress'] <= 0) {
            return 0.0;
        }

        // Ha még soha nem fejlesztett (NULL), a regisztráció napját tekinthetjük kiindulópontnak.
        // Ebben az esetben nincs mit csökkenteni — 0-ról nem lehet lejjebb menni.
        if (empty($user['last_virus_development_at'])) {
            return 0.0;
        }

        $lastDev = new \DateTimeImmutable($user['last_virus_development_at'], new \DateTimeZone('UTC'));
        $now     = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        // Hány teljes 24 órás periódus telt el?
        $elapsedSeconds = $now->getTimestamp() - $lastDev->getTimestamp();
        $elapsedDays    = (int)floor($elapsedSeconds / 86400);

        if ($elapsedDays <= 0) {
            return 0.0;
        }

        $decayAmount = round($elapsedDays * 0.2, 2);
        $newProgress = max(0.0, (float)$user['virus_progress'] - $decayAmount);
        $actualDecay = round((float)$user['virus_progress'] - $newProgress, 2);

        // 0.2% decay for users who haven't developed their virus in the last 24 hours.
        // Limit it so it doesn't go below 0%.
        $this->db->executeStatement(
            "UPDATE users SET virus_progress = ? WHERE id = ?",
            [$newProgress, $id]
        );

        return $actualDecay;
    }
}
