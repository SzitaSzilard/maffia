<?php
declare(strict_types=1);

namespace Netmafia\Modules\Combat\Domain;

class CombatNarrator
{
    private array $templates = [
        'attacker_win' => [
            1 => "Megtámadtad {defender}-t, aki jóval gyengébb volt nálad. A(z) {weapon} fegyvereddel, {ammo} lőszer elhasználásával szitává lőtted! {damage} életerőt vesztett és {money} dollárt zsákmányoltál tőle.",
            2 => "{defender} mit sem sejtve sétált, amikor rárontottál. A(z) {weapon} könyörtelenül tette a dolgát. A végeredmény: {damage} sebzés és {money} dollár a zsebedben.",
            3 => "Rajtaütöttél {defender}-n egy sötét sikátorban. Esélye sem volt a(z) {weapon} ellen. {damage} sebzést kapott, te pedig {money} dollárral gazdagodtál.",
            4 => "Gyors és kíméletlen támadás volt. {defender} csak kapkodta a fejét, miközben a(z) {weapon} dolgozott. {ammo} töltény bánta, de megérte: {money} dollár ütötte a markod.",
            5 => "{defender} megpróbált ellenállni, de a(z) {weapon} túl nagy falatnak bizonyult. {damage} sebzést szenvedett el, és kénytelen volt átadni {money} dollárt."
        ],
        'attacker_lose' => [
            1 => "Megtámadtad {defender}-t, de felkészültebb volt a vártnál. A visszavágása fájdalmas volt: {damage} életerőt vesztettél.",
            2 => "Túlbecsülted az erődet. {defender} profi módon hárította a támadásodat, és csúnyán helybenhagyott. {damage} sérülést szenvedtél.",
            3 => "{defender} nem ijedt meg tőled. Keményen védekezett, és te húztad a rövidebbet. A vereség ára {damage} életerő.",
            4 => "A támadásod kudarcba fulladt. {defender} erősebbnek bizonyult, és {damage} sebzéssel küldött haza.",
            5 => "Nem ez volt a legjobb ötleted. {defender} visszaverte a támadást, te pedig {damage} életerő mínusszal távoztál."
        ],
        'defender_win' => [
            1 => "{attacker} megpróbált megtámadni, de te résen voltál! Sikeresen visszaverted, és {damage} sebzést okoztál neki.",
            2 => "{attacker} azt hitte, könnyű préda leszel. Tévedett. A védekezésed áttörhetetlen volt, ő pedig {damage} sérüléssel bánta.",
            3 => "Váratlanul ért {attacker} támadása, de nem estél kétségbe. Keményen visszavágtál, {damage} életerőt sebezve rajta.",
            4 => "{attacker} fegyvert rántott rád, de te gyorsabb voltál. A támadása meghiúsult, és ő húzta a rövidebbet ({damage} sebzés).",
            5 => "Sikeresen megvédted magad {attacker} ellen! A támadó {damage} sérüléssel, dolgavégezetlenül távozott."
        ],
        'defender_lose' => [
            1 => "{attacker} rajtad ütött, és nem tudtad kivédeni. A(z) {weapon} {damage} sebzést okozott, és elvitt {money} dollárt.",
            2 => "Alulmaradtál {attacker} támadásával szemben. Kegyetlenül helybenhagyott ({damage} sebzés), és kirabolt ({money} dollár).",
            3 => "{attacker} túlerőben volt. A védekezésed összeomlott, {damage} életerőt és {money} dollárt vesztettél.",
            4 => "Fájdalmas vereség. {attacker} a(z) {weapon} segítségével győzött le. Veszteséged: {damage} élet és {money} dollár.",
            5 => "{attacker} meglepett, és nem volt esélyed. {damage} sebzést kaptál, és a pénztárcád is bánta ({money} dollár)."
        ],
        'escape_attacker' => [ // Attacker view when defender escapes
            1 => "Megtámadtad {defender}-t, de ő rafináltabb volt! Az alapértelmezett {vehicle} autóját használta menekülésre, ezért döntetlen lett a kimenetel.",
            2 => "Már majdnem elkaptad {defender}-t, de bepattant a {vehicle} volánja mögé és elhajtott. A harc elmaradt.",
            3 => "{defender} nem állt le harcolni. Gázt adott a {vehicle}-nek, és köddé vált előtted.",
            4 => "Túl lassú voltál! {defender} a {vehicle} segítségével egérutat nyert.",
            5 => "Dühösen nézted végig, ahogy {defender} elhajt a {vehicle}-lel. Ma nem sikerült elkapnod."
        ],
        'escape_defender' => [ // Defender view when they escape
            1 => "{attacker} megtámadott, de te nem estél pánikba. Bepattantál a {vehicle} volánja mögé, és sikeresen elmenekültél!",
            2 => "Forró lett a talaj a lábad alatt {attacker} miatt, de a {vehicle} megmentett. A gázba tapostál és otthagytad.",
            3 => "{attacker} elől sikerült kereket oldanod a hűséges {vehicle} segítségével. Ez most megúsztad!",
            4 => "Nem álltál le verekedni {attacker}-rel. A {vehicle} gyorsabb volt nála, így sértetlenül távoztál.",
            5 => "A legjobb védekezés a futás... vagyis a vezetés! A {vehicle} kimentett a bajból {attacker} ellen."
        ],
        // Attacker uses vehicle (Bonus scenarios)
        'attacker_win_vehicle' => [
            1 => "A(z) {attacker_vehicle} motorja hangosan felbőgött, ahogy {defender} mellé értél az utcán. Leengedted az ablakot, majd egy {weapon} ropogása törte meg a város csendjét. {ammo} golyót lőttél ki. A célpont nem volt felkészülve a váratlan támadásra.\n\nGyőzelem! Az akció során szereztél: {money} dollárt. A célpont (-{damage} Életerő) sérüléssel fekszik a betonon.",
            2 => "A(z) {attacker_vehicle} volánja mögül nyitottál tüzet {defender}-re. A sebességelőnyöd miatt esélye sem volt. {damage} életerőt kapott be, és {money} dollárt zsákmányoltál.",
            3 => "{defender} nem tudott mit kezdeni a(z) {attacker_vehicle} kiszámíthatatlan manővereivel. A halálos vezetésed {damage} sebzést és {money} dollárt ért.",
            4 => "A(z) {attacker_vehicle} fényszórójával elvakítottad {defender}-t, majd a(z) {weapon} megtette a magáét. A meglepetés ereje {damage} sebzést és {money} dollárt hozott a konyhára.",
            5 => "Száguldva érkeztél a(z) {attacker_vehicle}-lel, és {defender} tehetetlen volt a {ammo} felé szálló golyó ellen. {damage} sebzést okoztál neki, miközben elvettél tőle {money} dollárt."
        ],
        'attacker_lose_vehicle' => [
            1 => "Bár a(z) {attacker_vehicle}-lel támadtál, {defender} kifogott rajtad. A járműved sem mentett meg a {damage} sebzéstől.",
            2 => "{defender} kilőtte a kerekedet! A(z) {attacker_vehicle} irányíthatatlanná vált, és te húztad a rövidebbet ({damage} sebzés).",
            3 => "Túl magabiztos voltál a(z) {attacker_vehicle} kormánya mögött. {defender} kijátszott, és {damage} sebzést okozott.",
            4 => "A(z) {attacker_vehicle} nem nyújtott elég fedezéket. {defender} keményen visszavágott ({damage} sebzés).",
            5 => "Hiába a gyors {attacker_vehicle}, {defender} jobb stratégiát választott. Vesztesen távoztál ({damage} sebzés)."
        ]
    ];

    public function getRandomScenarioId(string $type): int
    {
        if (!isset($this->templates[$type])) {
            return 1;
        }
        return array_rand($this->templates[$type]);
    }

    public function generateNarrative(array $logData, bool $isViewerAttacker): string
    {
        // Determine outcome type
        $winnerId = $logData['winner_id'];
        $viewerId = $isViewerAttacker ? $logData['attacker_id'] : $logData['defender_id'];
        $opponentId = $isViewerAttacker ? $logData['defender_id'] : $logData['attacker_id'];
        
        $type = '';
        
        // Check for Escape first (Draw?)
        // If winner_id is NULL or 0? 
        // In CombatService, if escape happens, who is winner?
        // Usually escape means no winner or special state.
        // Let's check CombatService logic later. Assuming 'winner_id' is null on escape?
        // Or if vehicle_used_defender is true and no points/damage?
        
        // Based on current CombatService, escape just ends fight?
        // Need to check CombatService again.
        
        // Let's define types:
        if (empty($winnerId)) {
             // Likely escape or draw
             if (!empty($logData['vehicle_used_defender'])) {
                 $type = $isViewerAttacker ? 'escape_attacker' : 'escape_defender';
             } else {
                 // Unknown draw
                 return "Döntetlen.";
             }
        } elseif ($winnerId == $viewerId) {
            $type = $isViewerAttacker ? 'attacker_win' : 'defender_win';
            // Check for vehicle usage specific flavor (Attacker Only for now)
            if ($isViewerAttacker && !empty($logData['vehicle_used_attacker'])) {
                $type = 'attacker_win_vehicle';
            }
        } else {
            $type = $isViewerAttacker ? 'attacker_lose' : 'defender_lose';
            if ($isViewerAttacker && !empty($logData['vehicle_used_attacker'])) {
                $type = 'attacker_lose_vehicle';
            }
        }

        // Get Scenario ID from battle_report or fallback to 1
        $report = isset($logData['battle_report']) ? json_decode($logData['battle_report'], true) : [];
        $scenarioId = $report['scenario_id'] ?? 1;

        // Fallback if type changed drastically or invalid ID
        $template = $this->templates[$type][$scenarioId] ?? $this->templates[$type][1];

        // Placeholders
        // [FIX] htmlspecialchars() — Stored XSS védelem (|raw renderelés miatt ITT kell)
        $placeholders = [
            '{attacker}' => '<strong>' . htmlspecialchars(ucfirst($logData['attacker_name'] ?? 'Ismeretlen'), ENT_QUOTES, 'UTF-8') . '</strong>',
            '{defender}' => '<strong>' . htmlspecialchars(ucfirst($logData['defender_name'] ?? 'Ismeretlen'), ENT_QUOTES, 'UTF-8') . '</strong>',
            '{damage}' => (int)($logData['damage_dealt'] ?? 0),
            '{money}' => number_format((int)($logData['money_stolen'] ?? 0), 0, '.', ' '),
            '{ammo}' => (int)($logData['ammo_used_attacker'] ?? 0),
            '{weapon}' => htmlspecialchars($report['attacker_weapon'] ?? 'kézifegyver', ENT_QUOTES, 'UTF-8'),
            '{vehicle}' => htmlspecialchars($report['defender_vehicle'] ?? 'jármű', ENT_QUOTES, 'UTF-8'),
            '{attacker_vehicle}' => htmlspecialchars($report['attacker_vehicle'] ?? 'jármű', ENT_QUOTES, 'UTF-8'),
        ];

        $narrativeText = str_replace(array_keys($placeholders), array_values($placeholders), $template);

        // UI Tooltip Extra Stats
        $moneyStolen = (int)($logData['money_stolen'] ?? 0);
        $damageDealt = (int)($logData['damage_dealt'] ?? 0);
        $usedAmmo = (int)($logData['ammo_used_attacker'] ?? 0);

        if ($moneyStolen > 0 || $damageDealt > 0 || $usedAmmo > 0) {
            $narrativeText .= "<br><br>";
            
            if ($moneyStolen > 0) {
                $moneyLabel = ($winnerId == $viewerId) ? "Pénz nyereség" : "Pénz veszteség";
                $narrativeText .= $moneyLabel . ": $" . number_format((float)$moneyStolen, 0, '.', ',') . "<br>";
            }
            
            if ($damageDealt > 0) {
                $healthLabel = ($winnerId == $viewerId) ? "Okozott sebzés" : "Élet veszteség";
                $narrativeText .= $healthLabel . ": " . number_format((float)$damageDealt, 0, '.', ',') . "<br>";
            }
            
            if ($usedAmmo > 0) {
                if ($usedAmmo <= 100) {
                    $minAmmo = 0;
                    $maxAmmo = 100;
                } else {
                    $minAmmo = (int)floor($usedAmmo * 0.7);
                    $maxAmmo = (int)ceil($usedAmmo * 1.5);
                    $roundTo = pow(10, max(1, floor(log10($usedAmmo)) - 1));
                    $minAmmo = max(0, floor($minAmmo / $roundTo) * $roundTo);
                    $maxAmmo = ceil($maxAmmo / $roundTo) * $roundTo;
                }
                $attackerNameText = htmlspecialchars(ucfirst($logData['attacker_name'] ?? 'Ismeretlen'), ENT_QUOTES, 'UTF-8');
                $namePrefix = $isViewerAttacker ? "Saját" : $attackerNameText;
                $narrativeText .= $namePrefix . " támadásra felhasznált tölténye: " . number_format((float)$minAmmo, 0, '.', ',') . " - " . number_format((float)$maxAmmo, 0, '.', ',') . " db között<br>";
            }
        }

        return $narrativeText;
    }
}
