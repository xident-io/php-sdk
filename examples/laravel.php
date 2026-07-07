<?php
/**
 * Xident PHP SDK — Laravel Integration Example
 *
 * Add to your routes/web.php or a controller.
 */

// ─── config/services.php ───
// 'xident' => [
//     'secret_key' => env('XIDENT_SECRET_KEY'),
//     'webhook_secret' => env('XIDENT_WEBHOOK_SECRET'),
// ],

// ─── app/Http/Controllers/VerificationController.php ───

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Xident\SDK\Client;
use Xident\SDK\Exceptions\XidentException;

class VerificationController extends Controller
{
    private Client $xident;

    public function __construct()
    {
        $this->xident = new Client(
            apiKey: config('services.xident.secret_key'),
        );
    }

    /** Start verification — redirect user to Xident widget. */
    public function start(Request $request): RedirectResponse
    {
        $session = $this->xident->verification()->init([
            'callback_url' => route('verification.callback'),
            'min_age'      => 18,
            'success_url'  => route('verification.success'),
            'failed_url'   => route('verification.failed'),
            'user_id'      => (string) $request->user()?->id,
            'theme'        => 'system',
        ]);

        return redirect($session->verifyUrl);
    }

    /** Handle callback — verify result server-side. */
    public function callback(Request $request): RedirectResponse
    {
        $token = $request->validate(['token' => 'required|string'])['token'];

        try {
            $result = $this->xident->verification()->getResult($token);

            if ($result->isVerified()) {
                // Store verification in your database
                $request->user()?->update([
                    'age_verified'    => true,
                    'age_bracket'     => $result->ageBracket(),
                    'verified_method' => $result->method(),
                    'verified_at'     => now(),
                ]);
                return redirect()->route('verification.success');
            }

            return redirect()->route('verification.failed');
        } catch (XidentException $e) {
            report($e);
            return redirect()->route('verification.failed');
        }
    }
}

// ─── routes/web.php ───
// Route::middleware('auth')->group(function () {
//     Route::get('/verify', [VerificationController::class, 'start'])->name('verification.start');
//     Route::get('/verify/callback', [VerificationController::class, 'callback'])->name('verification.callback');
//     Route::view('/verify/success', 'verification.success')->name('verification.success');
//     Route::view('/verify/failed', 'verification.failed')->name('verification.failed');
// });
