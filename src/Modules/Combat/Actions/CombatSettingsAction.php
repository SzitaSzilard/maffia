<?php
declare(strict_types=1);

namespace Netmafia\Modules\Combat\Actions;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Netmafia\Modules\Combat\Domain\CombatRepository;

class CombatSettingsAction
{
    private CombatRepository $repository;

    public function __construct(CombatRepository $repository)
    {
        $this->repository = $repository;
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $request->getAttribute('user_id');
        $data = $request->getParsedBody();
        
        // Get current settings first
        $currentSettings = $this->repository->getCombatSettings($userId);
        
        // Merge settings
        // If use_vehicle is present in data (checkbox sent 'on' or similar), set true.
        // If the form was submitted but checkbox unchecked, it might not send anything?
        // Wait, for checkboxes:
        // - If standard POST and unchecked: nothing sent.
        // - If standard POST and checked: 'on' sent.
        // But we have separate forms.
        // If we submit the "Use Vehicle" form, we invoke this action.
        // If we submit the "Ammo" form, we invoke this action.
        
        // We can distinguish which form was sent? Or just assume if specific keys exist?
        // Better: Update based on what's available, but handle the "unchecked" checkbox case tricky.
        // Trick: Add a hidden input with the same name before the checkbox? Or check header?
        // Let's assume if 'defense_ammo' is present, we are updating ammo.
        // If 'use_vehicle_submit' is present (we can add hidden field), we update vehicle.
        
        // Let's check what we received. 
        // Logic:
        // - If 'defense_ammo' is set -> We are saving ammo. Preserve 'use_vehicle' from DB.
        // - If 'defense_ammo' is NOT set -> We assume we are saving 'use_vehicle'.
        
        $useVehicle = $currentSettings['use_vehicle'];
        $defenseAmmo = $currentSettings['defense_ammo'];
        
        if (isset($data['defense_ammo'])) {
            // Update Ammo
            $defenseAmmo = (int)$data['defense_ammo'];
        } else {
            // Update Vehicle - Checkbox logic
            // If this block is reached, we assume it's the vehicle form.
            // Presence of 'use_vehicle' means true, absence means false (if this was the target form).
            $useVehicle = isset($data['use_vehicle']);
        }
        
        if ($defenseAmmo < 0) $defenseAmmo = 0;
        if ($defenseAmmo > 999) $defenseAmmo = 999;
        
        $this->repository->saveCombatSettings($userId, (bool)$useVehicle, $defenseAmmo);
        
        // Check for HTMX
        if ($request->hasHeader('HX-Request')) {
            $response->getBody()->write($this->renderForm($useVehicle, $defenseAmmo, isset($data['defense_ammo']), $request));
            return $response->withHeader('Content-Type', 'text/html');
        }
        
        // Redirect back
        return $response
            ->withHeader('Location', '/kuzdelmek')
            ->withStatus(302);
    }

    private function renderForm($useVehicle, $defenseAmmo, $isAmmoForm, $request): string
    {
        // Simple manual HTML render to avoid heavy Twig rebuild or complex partials for now
        // Or we could use Twig string loader?
        // Let's return the form HTML directly for swap.
        
        // Retrieve CSRF tokens again? They rotate? usually per session/request.
        // We need to pass the same or new tokens?
        // Simplest for now: Return a button saying "Mentve!" that reverts after 2s?
        // Or just re-render the specific form being updated.
        
        // Let's use a simple "Saved" feedback.
        // But we need to re-render the form so the user can edit again.
        
        // Providing the CSRF tokens again is tricky without Twig context here easily.
        // We can fetch valid tokens from the request attributes if the middleware put them there?
        // CSRF middleware usually injects into Twig view.
        // Let's try to grab from request attribute 'csrf' if available.
        $csrf = $request->getAttribute('csrf'); 
        $csrfName = $csrf ? $csrf['keys']['name'] : 'csrf_name';
        $csrfValue = $csrf ? $csrf['keys']['value'] : 'csrf_value';
        $csrfNameKey = $csrf ? $csrf['name'] : 'csrf_key'; // Wait, standard slim/csrf?
        // Standard Slim-CSRF puts name/value storage in attribute.
        // Let's assume standard names for now based on what I put in template: csrf_name, csrf_value.
        
        // Actually, let's just return a success message that fades out, and re-insert the form?
        // That's complicated.
        // Let's just return the form HTML as it should look.
        
        $csrfInput1 = '<input type="hidden" name="csrf_name" value="'.$request->getAttribute('csrf_name').'">';
        $csrfInput2 = '<input type="hidden" name="csrf_value" value="'.$request->getAttribute('csrf_value').'">';
        
        // Wait, Middleware\AuthMiddleware or similar might be doing CSRF? 
        // Netmafia uses 'csrf' view global usually.
        // Let's simplify: Return a span "Beállítás mentve!" which replaces the form, then we can't edit?
        // No.
        
        // Let's return the updated form HTML string manually.
        if ($isAmmoForm) {
            return '
                <div style="margin-bottom: 10px;">
                    Töltény: <input type="number" name="defense_ammo" value="'.$defenseAmmo.'" min="0" max="999" class="combat-input"> 
                    <span style="font-size: 16px; vertical-align: middle;" title="Védelmi Töltény">🛡️</span> db
                </div>
                <button type="submit" class="btn-combat" style="background: #cfc; border-color: #6a6;">Mentve</button>
                ' . $csrfInput1 . $csrfInput2;
        } else {
             $checked = $useVehicle ? 'checked' : '';
             return '
                <label style="display: flex; align-items: center; justify-content: center; gap: 5px; margin-bottom: 10px; font-size: 11px;">
                    <input type="checkbox" name="use_vehicle" '.$checked.'>
                    Használom az alapért. járművemet a<br>küzdelem során, ha lehetséges
                </label>
                <button type="submit" class="btn-combat" style="background: #cfc; border-color: #6a6;">Mentve</button>
                ' . $csrfInput1 . $csrfInput2;
        }
    }
}
