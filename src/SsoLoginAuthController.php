<?php
/*
 * This file is part of Flarum.
 *
 * (c) Toby Zerner <toby.zerner@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Flarum\Auth\Sso;

use Flarum\Api\Client;
use Flarum\Core\User;
use Flarum\Forum\AuthenticationResponseFactory;
use Flarum\Foundation\Application;
use Flarum\Http\Controller\ControllerInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\Session;

class SsoLoginAuthController implements ControllerInterface
{
    /**
     * @var Client
     */
    protected $api;

    /**
     * @var SettingsRepositoryInterface
     */
    protected $settings;

    /**
     * @var AuthenticationResponseFactory
     */
    protected $authResponse;

    /**
     * @var Application
     */
    protected $app;

    /**
     * @param AuthenticationResponseFactory $authResponse
     * @param SettingsRepositoryInterface   $settings
     */
    public function __construct(Client $api, AuthenticationResponseFactory $authResponse, SettingsRepositoryInterface $settings, Application $app)
    {
        $this->api = $api;
        $this->authResponse = $authResponse;
        $this->settings = $settings;
        $this->app = $app;
    }

    /**
     * @param ServerRequestInterface $request
     *
     * @return \Psr\Http\Message\ResponseInterface|RedirectResponse
     */
    public function handle(ServerRequestInterface $request)
    {
        /** @var Session $session */
        $session = $request->getAttribute('session');
        $noncePreviouslySend = $session->get('sso_nonce');
        $queryParams = $request->getQueryParams();
        $sso = array_get($queryParams, 'sso');
        $sig = array_get($queryParams, 'sig');
        $secret = $this->settings->get('flarum-ext-sso.secret');
        //If request valid
        if (FlarumSingleSignOn::validate($sso, $sig, $secret)) {
            $parsePayload = FlarumSingleSignOn::parsePayload($sso);
            $forwardNonce = $parsePayload["nonce"];
            if ($noncePreviouslySend == $forwardNonce) {
                $email = $parsePayload["email"];
                $username = preg_replace('/[^a-z0-9-_]/i', '', $parsePayload["username"]);
                //Search user for loggin
                $identification = ['email' => $email];
                $avatarUrl = isset($parsePayload['avatarUrl']) ? $parsePayload["avatarUrl"] : "";
                $originalUrl = isset($parsePayload['originalUrl']) ? $parsePayload["originalUrl"] : $this->app->url();
                $roles = isset($parsePayload['roles']) ? $parsePayload["roles"] : [];

                if ($user = User::where('email', $email)->first()) { //Update user

                    $bodyOfUpdate = $this->updateUser($user->id, $username, $roles, $avatarUrl);
                    $this->assertOrExit($bodyOfUpdate->data);

                    return $this->redirectWithAuth($originalUrl, $request, $identification);
                } else { //Create user

                    $bodyOfRegistration = $this->registerUser($username, $email, $avatarUrl);
                    $this->assertOrExit($bodyOfRegistration->data);
                    //Currently is not possible to assign groups on creation, so try to update.
                    $bodyOfUpdate = $this->updateUser($user->id, $username, $roles, $avatarUrl);
                    $this->assertOrExit($bodyOfUpdate->data);

                    return $this->redirectWithAuth($originalUrl, $request, $identification);
                }
            }
        }
        $this->displayErrorMessage();
        exit;
    }

    public function assertOrExit($data)
    {
        if (!isset($data)) {
            $this->displayErrorMessage();
            exit;
        }
    }

    public function displayErrorMessage() {
        echo 'Invalid sso login. Please contact an administrator.';
    }

    /**
     * @param       $url
     * @param       $request
     * @param       $identification
     * @param array $suggestions
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function redirectWithAuth($url, $request, $identification, $suggestions = [])
    {
        $request->getAttribute('session')->remove('sso_nonce');

        return $this->authResponse->make($request, $identification, $suggestions)
            ->withHeader('Location', $url)->withStatus(302); //Force a redirect
    }

    /**
     * @param $userId
     * @return mixed
     * @internal param $username
     * @internal param $avatarUrl
     *
     */
    public function updateUser($userId, $username, $roles, $avatarUrl)
    {
        $controller = 'Flarum\Api\Controller\UpdateUserController';
        $mappedRoles = array_map(function ($role) {
            return ['id' => $role];
        }, explode(",", $roles));
        $actor = new SsoAdminUser();
        $data = [
            'attributes' => [
                'username' => $username,
                'avatarUrl' => $avatarUrl,
            ],
        ];
        if (count($roles) > 0) {
            $data = array_merge($data, [
                'relationships' => [
                    'groups' => ['data' => $mappedRoles],
                ],
            ]);
        }
        $response = $this->api->send($controller, $actor, ['id' => $userId], ['data' => $data]);
        $bodyOfRegistration = json_decode($response->getBody());

        return $bodyOfRegistration;
    }

    /**
     * @param $username
     * @param $email
     * @param $avatarUrl
     *
     * @return mixed
     */
    public function registerUser($username, $email, $avatarUrl)
    {
        $controller = 'Flarum\Api\Controller\CreateUserController';
        $actor = new SsoAdminUser();
        $body = [
            'data' => [
                'attributes' => [
                    'username' => $username,
                    'email' => $email,
                    'password' => str_random(20),
                    'isActivated' => true,
                    'avatarUrl' => $avatarUrl,
                ],
            ],
        ];
        $response = $this->api->send($controller, $actor, [], $body);
        $bodyOfRegistration = json_decode($response->getBody());

        return $bodyOfRegistration;
    }
}