<?php
/**
 * Xident PHP SDK — Symfony Integration Example
 */

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Xident\SDK\Client;
use Xident\SDK\Exceptions\XidentException;

class VerificationController extends AbstractController
{
    /**
     * The minimum age this site requires. Decided here, on the server: never
     * take it from the request, or the browser could ask for a lower age.
     */
    private const REQUIRED_MIN_AGE = 18; // 12 to 25, rounded up to 12, 15, 18, 21 or 25

    private Client $xident;

    public function __construct()
    {
        // Register as a service in services.yaml for proper DI
        $this->xident = new Client(
            apiKey: $_ENV['XIDENT_SECRET_KEY'],
        );
    }

    #[Route('/verify/start', name: 'verify_start')]
    public function start(): RedirectResponse
    {
        // user_id is required, so this route needs a logged-in user.
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED');

        $session = $this->xident->verification()->init([
            'callback_url' => $this->generateUrl('verify_callback', [], 0),
            'user_id'      => $this->getUser()->getUserIdentifier(), // your own id for this person
            'min_age'      => self::REQUIRED_MIN_AGE,
        ]);

        // verifyUrl is always https://verify.xident.io — safe to redirect
        $verifyUrl = $session->verifyUrl;
        assert(str_starts_with($verifyUrl, 'https://verify.xident.io'), 'Unexpected verify URL');
        return new RedirectResponse($verifyUrl);
    }

    #[Route('/verify/callback', name: 'verify_callback')]
    public function callback(Request $request): Response
    {
        // The result must belong to the signed-in user, so this route needs one too.
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED');

        $token = $request->query->get('token', '');
        if ($token === '') {
            return $this->redirectToRoute('verify_failed');
        }

        try {
            $result = $this->xident->verification()->getResult($token);

            // Grant only when both hold:
            // 1. The result belongs to the signed-in user. Compare with the id
            //    this server sent as user_id, never with the user_id in the
            //    callback URL: a result token copied from someone else's
            //    callback is a real success, for somebody else.
            // 2. It proves the age this site requires. An 18+ result does not
            //    open a 21+ page, and an ID-only result proves no age.
            if ($result->externalUserId === $this->getUser()->getUserIdentifier()
                && $result->provesAge(self::REQUIRED_MIN_AGE)
            ) {
                $request->getSession()->set('age_verified', true);
                $request->getSession()->set('age_bracket', $result->ageBracket());
                return $this->redirectToRoute('verify_success');
            }

            return $this->redirectToRoute('verify_failed');
        } catch (XidentException $e) {
            return $this->redirectToRoute('verify_failed');
        }
    }
}
