<?php
/**
 * @package    xero-php-sample-app
 * @author     Michael Calcinai <michael@calcin.ai>
 */

namespace App\Controller;

use App\Helper\XeroSessionStorage;
use League\Plates\Engine;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use XeroPHP\Application\PublicApplication;
use XeroPHP\Models\Accounting\Organisation;
use XeroPHP\Remote\Request;
use XeroPHP\Remote\URL;
use Zend\Diactoros\Response\RedirectResponse;

class ApplicationController extends BaseController
{

    /**
     * @var XeroSessionStorage
     */
    private $xeroSessionStorage;

    /**
     * This overrides the base controller so the Xero session is injected
     *
     * @param Engine $plates
     * @param PublicApplication $xero
     * @param XeroSessionStorage $xeroSessionStorage
     */
    public function __construct(Engine $plates, PublicApplication $xero, XeroSessionStorage $xeroSessionStorage)
    {
        parent::__construct($plates, $xero);
        $this->xeroSessionStorage = $xeroSessionStorage;
    }

    /**
     * This is the main UI, but will redirect to the connect URL if there is no Xero Session
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @return ResponseInterface
     * @throws \XeroPHP\Remote\Exception
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response)
    {
        //If there's not a Xero session, redirect to the connect method
        if (null === $session = $this->xeroSessionStorage->getSession()) {
            return new RedirectResponse('/application/connect');
        }

        //If we don't have the org name, go and fetch it
        if (!isset($session->organisation_name)) {
            /** @var Organisation $organisation */
            $organisation = current($this->xero->load(Organisation::class)->execute());
            $session->organisation_name = $organisation->getName();
        }

        $response->getBody()->write(
            $this->plates->render('index', ['organisation_name' => $session->organisation_name])
        );

        return $response->withStatus(200);
    }

    /**
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @return ResponseInterface
     */
    public function connect(ServerRequestInterface $request, ResponseInterface $response)
    {
        $response->getBody()->write(
            $this->plates->render('connect', [])
        );

        return $response->withStatus(200);
    }

    /**
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @return RedirectResponse
     * @throws \XeroPHP\Remote\Exception
     */
    public function connectRedirect(ServerRequestInterface $request, ResponseInterface $response)
    {
        $url = new URL($this->xero, URL::OAUTH_REQUEST_TOKEN);
        $request = new Request($this->xero, $url);

        //This will throw a BadRequestException if something is wrong with the keys
        //it will be caught higher up the stack and display a custom 500
        $request->send();
        $oauth_response = $request->getResponse()->getOAuthResponse();

        $this->xeroSessionStorage->setSession(
            $oauth_response['oauth_token'],
            $oauth_response['oauth_token_secret']
        );

        return new RedirectResponse($this->xero->getAuthorizeURL($oauth_response['oauth_token']));
    }


    /**
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @return RedirectResponse
     * @throws \XeroPHP\Remote\Exception
     */
    public function xeroCallback(ServerRequestInterface $request, ResponseInterface $response)
    {
        $params = $request->getQueryParams();

        $this->xero->getOAuthClient()->setVerifier($params['oauth_verifier']);

        $url = new URL($this->xero, URL::OAUTH_ACCESS_TOKEN);
        $request = new Request($this->xero, $url);

        $request->send();
        $oauth_response = $request->getResponse()->getOAuthResponse();

        $this->xeroSessionStorage->setSession(
            $oauth_response['oauth_token'],
            $oauth_response['oauth_token_secret'],
            $oauth_response['oauth_expires_in']
        );

        //All successful, redirect to index
        return new RedirectResponse('/');
    }


    public function disconnect(ServerRequestInterface $request, ResponseInterface $response)
    {
        $this->xeroSessionStorage->deleteSession();

        return new RedirectResponse('/');
    }


}