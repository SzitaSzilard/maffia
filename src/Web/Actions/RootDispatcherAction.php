<?php
declare(strict_types=1);

namespace Netmafia\Web\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Netmafia\Modules\Profile\Actions\ProfileAction;
use Netmafia\Shared\Actions\UnderConstructionAction;
use Netmafia\Modules\Profile\Domain\ProfileService;

class RootDispatcherAction
{
    private ProfileAction $profileAction;
    private UnderConstructionAction $underConstructionAction;
    private ProfileService $profileService;

    private const VALID_MODULES = [
        'bank', 'utazas', 'vasarlas', 'orszagok', 'posta', 'rendorseg', 'etterem', 'korhaz', 'toltenygyar', 'loter',
        'emberrablas', 'szervezett-bunozes', 'megbizasok', 'jarmulopas', 'e-bunozes', 'kisstilu-bunozes', 'vadkender', 'femkereskedelem', 'kakasviadal', 'oromlany', 'boxclub',
        'garazs', 'versenyzes', 'gyakorlopalya', 'szalonok', 'benzinkut',
        'poker', 'kaparos-sorsjegy', 'felkaru-rablo', 'blackjack', 'lotto', 'sportfogadas',
        'beallitasok', 'baratok', 'statisztikak', 'szabalyzat', 'gyik',
        'premium', 'kuldetesek', 'kuzdelmek', 'vallalkozas', 'adohatosag', 'munkakozvetito', 'tozsde', 'aukcio', 'piac',
        'banda', 'forum', 'helpdesk', 'gengszter-kereses', 'oldal-ajanlasa',
        'adminisztracio'
    ];

    public function __construct(
        ProfileAction $profileAction,
        UnderConstructionAction $underConstructionAction,
        ProfileService $profileService
    ) {
        $this->profileAction = $profileAction;
        $this->underConstructionAction = $underConstructionAction;
        $this->profileService = $profileService;
    }

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $slug = $args['slug'] ?? '';
        
        // 1. Check if slug Matches a Known Module
        // We prioritise modules to avoid DB lookup if it's a known static route name
        if (in_array($slug, self::VALID_MODULES)) {
            $args['module'] = $slug;
            return ($this->underConstructionAction)($request, $response, $args);
        }

        // 2. Check if slug Matches a User Profile
        $user = $this->profileService->getUserProfile($slug);

        if ($user) {
            $args['identifier'] = $slug;
            return ($this->profileAction)($request, $response, $args);
        }

        // 3. Neither? 404 Only!
        throw new \Slim\Exception\HttpNotFoundException($request, "Az oldal nem található.");
    }
}
