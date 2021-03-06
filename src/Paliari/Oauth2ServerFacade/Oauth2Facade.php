<?php
namespace Paliari\Oauth2ServerFacade;

use OAuth2\GrantType\ClientCredentials,
    OAuth2\GrantType\AuthorizationCode,
    OAuth2\GrantType\RefreshToken,
    OAuth2\Storage\Pdo,
    OAuth2\Response,
    OAuth2\Request,
    OAuth2\Server;

class Oauth2Facade
{

    /**
     * @var Oauth2Facade
     */
    protected static $_instance;

    /**
     * @var Pdo
     */
    protected $storage;

    /**
     * @var Server
     */
    protected $server;

    /**
     * @var UserProviderInterface
     */
    protected $userProvider;

    /**
     *
     */
    protected $resourceHandler;

    /**
     *
     * @param Storage|\PDO $storage
     *
     * @param array        $config default array(
     * 'access_lifetime'          => 3600,
     * 'www_realm'                => 'Service',
     * 'token_param_name'         => 'access_token',
     * 'token_bearer_header_name' => 'Bearer',
     * 'enforce_state'            => true,
     * 'require_exact_redirect_uri' => true,
     * 'allow_implicit'           => false,
     * 'allow_credentials_in_request_body' => true,
     * ).
     */
    public function __construct($storage, $config = array())
    {
        $this->storage = $storage;
        $config        = array_merge(array(
            'enforce_state' => false,
        ), $config);
        // Pass a storage object or array of storage objects to the OAuth2 server class
        $server = new Server($storage, $config);
        // Add the "Client Credentials" grant type (it is the simplest of the grant types)
        $server->addGrantType(new ClientCredentials($storage));
        // Add the "Authorization Code" grant type (this is where the oauth magic happens)
        $server->addGrantType(new AuthorizationCode($storage));
        $server->addGrantType(new RefreshToken($storage));
        $this->server = $server;
    }

    public function setResourceHandler($resourceHandler)
    {
        $this->resourceHandler = $resourceHandler;
    }

    /**
     * @param UserProviderInterface $userProvider
     */
    public function setUserProvider(UserProviderInterface $userProvider)
    {
        $this->userProvider = $userProvider;
    }

    /**
     * @return UserProviderInterface
     */
    public function getUserProvider()
    {
        return $this->userProvider;
    }

    public function frontController()
    {
        $path = new Path();
        switch ($path) {
            case "authorize":
                $this->authorize();
                break;
            case "token":
                $this->token();
                break;

        }
        if (preg_match("!resource/(.+)!", $path, $matches)) {
            $this->resource($matches[1]);
        }
    }

    public function authorize()
    {
        $this->getUserProvider()->verifyUser();
        $request  = Request::createFromGlobals();
        $response = new Response();
        // validate the authorize request
        if (!$this->server->validateAuthorizeRequest($request, $response)) {
            $response->send();
            die;
        }
        $client_id     = $request->query("client_id");
        $client        = $this->storage->getClientDetails($client_id);
        $user_id       = $this->getUserProvider()->getUserId();
        $is_authorized = $this->authorized($client_id, $user_id);
        // display an authorization form
        if (empty($_POST) && !$is_authorized) {
            $html = Tpl::authorize($client);
            exit($html);
        }
        // print the authorization code if the user has authorized your client
        $this->server->handleAuthorizeRequest($request, $response, $is_authorized, $user_id);
        if ($is_authorized) {
            // this is only here so that you get to see your code in the cURL request. Otherwise, we'd redirect back to the client
            $code = substr($response->getHttpHeader('Location'), strpos($response->getHttpHeader('Location'), 'code=') + 5, 40);
            $response->send();
            //exit("SUCCESS! Authorization Code: $code");
        }
        $response->send();
    }

    public function token()
    {
        $request = Request::createFromGlobals();
        // Handle a request for an OAuth2.0 Access Token and send the response to the client
        $tr = $this->server->handleTokenRequest($request);
        $tr->send();
    }

    public function resource($path)
    {
        // Handle a request for an OAuth2.0 Access Token and send the response to the client
        if (!$this->server->verifyResourceRequest(Request::createFromGlobals())) {
            $this->server->getResponse()->send();
            die;
        }
        $token  = $this->server->getAccessTokenData(Request::createFromGlobals());
        $return = array();
        if (is_callable($this->resourceHandler)) {
            $return = call_user_func($this->resourceHandler, $path, $token['user_id']);
        }
        echo json_encode($return);
    }

    /**
     * @param string $client_id
     * @param string $user_id
     *
     * @return bool
     */
    protected function authorized($client_id, $user_id)
    {
        if ($this->storage->getClientUser($client_id, $user_id)) {
            return true;
        }
        if ($_POST && 'yes' === @$_POST['authorized']) {
            $this->storage->setClientUser($client_id, $user_id);

            return true;
        }

        return false;
    }

}
