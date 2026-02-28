<?php

namespace App\Security;

use App\Entity\User;
use App\Entity\UserBilling;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\InvalidTokenException;
use Lexik\Bundle\JWTAuthenticationBundle\Security\Http\Authentication\AuthenticationSuccessHandler;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWSProvider\JWSProviderInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\PassportInterface;

class GoogleOAuth2Authenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly ClientRegistry $clientRegistry,
        private readonly EntityManagerInterface $em,
        private readonly ParameterBagInterface $parameterBag,
        private readonly JWSProviderInterface $jWSProviderInterface,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly AuthenticationSuccessHandler $authenticationSuccessHandler,

    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === 'connect_google_check';
    }

    public function authenticate(Request $request): Passport
    {
        $code = $request->query->get('code', null);
        $credential = $request->query->get('credential');
        $state = $request->query->get('state', null);

        $client = new \Google\Client([
            'client_id' => $this->parameterBag->get('app.google_oauth2_client_id'),
            'client_secret' => $this->parameterBag->get('app.google_oauth2_client_secret'),
            'redirect_uri' => $this->parameterBag->get('app.google_oauth2_client_redirect_uri'),
            'state' => $state
        ]);

        $result = null;
        $idToken = $credential;

        if ($code) {
            $result = $client->fetchAccessTokenWithAuthCode($code);
            if (!isset($result['id_token'])) throw new InvalidTokenException('Invalid JWT Token');
            $idToken = $result['id_token'] ?? null;
        }

        try {
            $googleUser = $client->verifyIdToken($idToken);
        } catch (\Exception $ex) {
            throw new InvalidTokenException('Invalid JWT Token');
        }


        $passport = new SelfValidatingPassport(
            userBadge: new UserBadge($idToken, function () use ($googleUser) {

                $email = $googleUser['email'];
                $id = $googleUser['sub'];
                $firstName = $googleUser['given_name'];
                $lastName = $googleUser['family_name'];
                $emailVerified = $googleUser['email_verified'] ?? false;


                $existingUser = $this->em->getRepository(User::class)->findOneBy(['googleId' => $id]);

                if ($existingUser) {
                    $existingUser->setLastConnectedAt(new \DateTimeImmutable());
                    $this->em->flush();
                    return $existingUser;
                }

                // If there is a match
                $existingUser = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
                if ($existingUser) {
                    $existingUser->setGoogleId($id);
                    return $existingUser;
                }

                $newUser = (new User())
                    ->setEmail($email)
                    ->setEmailVerified($emailVerified)
                    ->setGoogleId($id)
                    ->setActive(true)
                    ->setLastConnectedAt(new \DateTimeImmutable())
                    ->setRoles(['ROLE_USER'])
                    ->setUserBilling(
                        (new UserBilling())
                            ->setFirstName($firstName)
                            ->setLastName($lastName)
                    );



                $this->em->persist($newUser);
                $this->em->flush();
            })
        );
        $passport->setAttribute('payload', $idToken);
        $passport->setAttribute('token', $code);
        return $passport;
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // return new RedirectResponse($this->parameterBag->get('redirect_after_oauth2'));
        $user = $token->getUser();
        return $this->authenticationSuccessHandler->handleAuthenticationSuccess($user, $this->jwtManager->create($user));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $message = strtr($exception->getMessageKey(), $exception->getMessageData());

        return new Response($message, Response::HTTP_FORBIDDEN);
    }

    public function start(Request $request, AuthenticationException $authException = null): RedirectResponse
    {
        return new RedirectResponse(
            '/connect/', // might be the site, where users choose their oauth provider
            Response::HTTP_TEMPORARY_REDIRECT
        );
    }
}
