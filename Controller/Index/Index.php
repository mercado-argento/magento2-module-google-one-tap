<?php
/**
 * @category  Collab
 * @package   Collab\GoogleOneTap
 * @author    Marcin Jędrzejewski <m.jedrzejewski@collab.pl>
 * @copyright 2024 Collab
 * @license   MIT
 */

declare(strict_types=1);

namespace Collab\GoogleOneTap\Controller\Index;

use Collab\CustomerPasswordLessLogin\Service\LoginWithoutPassword;
use Collab\GoogleOneTap\Api\Data\ConfigInterface;
use Collab\GoogleOneTap\Service\GoogleApiClient;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface;

class Index implements HttpPostActionInterface
{
    public function __construct(
        protected ConfigInterface $config,
        protected GoogleApiClient $googleApiClient,
        protected RequestInterface $request,
        protected ResultFactory $resultFactory,
        protected LoginWithoutPassword $loginWithoutPassword,
        protected Session $customerSession,
        protected ManagerInterface $messageManager
    ) {
    }

    public function execute(): ResultInterface
    {
        if (!$this->config->isEnabled()) {
            $this->messageManager->addErrorMessage(__('Google One Tap is disabled.'));
            return $this->resultFactory->create(
                ResultFactory::TYPE_REDIRECT
            )->setPath($this->customerSession->getBeforeAuthUrl());
        }

        $credential = $this->request->getParam('credential');
        $payload = $this->googleApiClient->getUserInfo($credential);

        $csrfToken = $this->request->getParam('form_key');
        $nonce = (string)$this->customerSession->getGoogleOneTapNonce();
        $this->customerSession->setGoogleOneTapNonce(null);
        $emailVerified = $payload['email_verified'] ?? false;
        $email = $payload['email'] ?? '';
        $payloadNonce = $payload['nonce'] ?? '';

        if (
            !count($payload)
            || $csrfToken !== $this->config->getFormKey()
            || !$emailVerified
            || $email === ''
            || $nonce === ''
            || $payloadNonce !== $nonce
        ) {
            $this->messageManager->addErrorMessage(__('Invalid credentials or expired form key. Please try again...'));
        } else {
            $this->loginWithoutPassword->login([
                'email' => $email,
                'firstName' => $payload['given_name'] ?? '',
                'lastName' => $payload['family_name'] ?? ''
            ]);

            $this->messageManager->addSuccessMessage(__('You have been successfully logged in.'));
        }

        return $this->resultFactory->create(
            ResultFactory::TYPE_REDIRECT
        )->setPath($this->customerSession->getBeforeAuthUrl());
    }
}
