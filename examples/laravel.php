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
    /**
     * The minimum age this site requires. Decided here, on the server: never
     * take it from the request, or the browser could ask for a lower age.
     */
    private const REQUIRED_MIN_AGE = 18; // 12 to 25, rounded up to 12, 15, 18, 21 or 25

    private Client $xident;

    public function __construct()
    {
        $this->xident = new Client(
            apiKey: config('services.xident.secret_key'),
        );
    }

    /**
     * Start verification — redirect user to Xident widget.
     * The route sits behind the `auth` middleware, so there is always a user.
     */
    public function start(Request $request): RedirectResponse
    {
        $session = $this->xident->verification()->init([
            'callback_url' => route('verification.callback'),
            'user_id'      => (string) $request->user()->id, // required: your own id for this person
            'min_age'      => self::REQUIRED_MIN_AGE,
            'theme'        => 'system',
        ]);

        return redirect($session->verifyUrl);
    }

    /**
     * Handle callback: read the result server-side and decide.
     * Also behind the `auth` middleware: the result must belong to this user.
     */
    public function callback(Request $request): RedirectResponse
    {
        $token = $request->validate(['token' => 'required|string'])['token'];
        $user = $request->user();

        try {
            $result = $this->xident->verification()->getResult($token);

            // Grant only when both hold:
            // 1. The result belongs to the signed-in user. Compare with the id
            //    this server sent as user_id, never with the user_id in the
            //    callback URL: a result token copied from someone else's
            //    callback is a real success, for somebody else.
            // 2. It proves the age this site requires. An 18+ result does not
            //    open a 21+ page, and an ID-only result proves no age.
            if ($result->externalUserId === (string) $user->id
                && $result->provesAge(self::REQUIRED_MIN_AGE)
            ) {
                $user->update([
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
