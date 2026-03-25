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
        $session = $this->xident->verification()->init([
            'callback_url' => $this->generateUrl('verify_callback', [], 0),
            'min_age'      => 18,
            'success_url'  => $this->generateUrl('verify_success', [], 0),
            'failed_url'   => $this->generateUrl('verify_failed', [], 0),
        ]);

        // verifyUrl is always https://verify.xident.io — safe to redirect
        $verifyUrl = $session->verifyUrl;
        assert(str_starts_with($verifyUrl, 'https://verify.xident.io'), 'Unexpected verify URL');
        return new RedirectResponse($verifyUrl);
    }

    #[Route('/verify/callback', name: 'verify_callback')]
    public function callback(Request $request): Response
    {
        $token = $request->query->get('token', '');
        if ($token === '') {
            return $this->redirectToRoute('verify_failed');
        }

        try {
            $result = $this->xident->verification()->getResult($token);

            if ($result->isVerified()) {
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
