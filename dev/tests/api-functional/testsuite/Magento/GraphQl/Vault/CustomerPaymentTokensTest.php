<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\GraphQl\Vault;

use Magento\Integration\Api\CustomerTokenServiceInterface;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\TestCase\GraphQlAbstract;
use Magento\VaultGraphQl\Model\VisibleTokenRetriever;
use Magento\Vault\Model\ResourceModel\PaymentToken as TokenResource;
use Magento\Vault\Model\ResourceModel\PaymentToken\CollectionFactory;

class CustomerPaymentTokensTest extends GraphQlAbstract
{
    /**
     * @var CustomerTokenServiceInterface
     */
    private $customerTokenService;

    /**
     * @var VisibleTokenRetriever
     */
    private $paymentTokenManagement;

    /**
     * @var CollectionFactory
     */
    private $tokenCollectionFactory;

    /**
     * @var TokenResource
     */
    private $tokenResource;

    protected function setUp()
    {
        parent::setUp();

        $this->customerTokenService = Bootstrap::getObjectManager()->get(CustomerTokenServiceInterface::class);
        $this->paymentTokenManagement = Bootstrap::getObjectManager()->get(VisibleTokenRetriever::class);
        $this->tokenResource = Bootstrap::getObjectManager()->get(TokenResource::class);
        $this->tokenCollectionFactory = Bootstrap::getObjectManager()->get(CollectionFactory::class);
    }

    protected function tearDown()
    {
        parent::tearDown();

        $collection = $this->tokenCollectionFactory->create();
        $collection->addFieldToFilter('customer_id', ['eq' => 1]);

        foreach ($collection->getItems() as $token) {
            // Using the resource directly to delete. Deleting from the repository only makes token inactive
            $this->tokenResource->delete($token);
        }
    }

    /**
     * @magentoApiDataFixture Magento/Vault/_files/payment_tokens.php
     */
    public function testGetCustomerPaymentTokens()
    {
        $currentEmail = 'customer@example.com';
        $currentPassword = 'password';

        $query = <<<QUERY
query {
    customerPaymentTokens {
        items {
            public_hash
            details
            payment_method_code
            type
        }
    }
}
QUERY;
        $response = $this->graphQlQuery($query, [], '', $this->getCustomerAuthHeaders($currentEmail, $currentPassword));

        $this->assertEquals(1, count($response['customerPaymentTokens']['items']));
        $this->assertArrayHasKey('public_hash', $response['customerPaymentTokens']['items'][0]);
        $this->assertArrayHasKey('details', $response['customerPaymentTokens']['items'][0]);
        $this->assertArrayHasKey('payment_method_code', $response['customerPaymentTokens']['items'][0]);
        $this->assertArrayHasKey('type', $response['customerPaymentTokens']['items'][0]);
        $this->assertArrayNotHasKey('gateway_token', $response['customerPaymentTokens']['items'][0]);
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage GraphQL response contains errors: The current customer isn't authorized.
     */
    public function testGetCustomerPaymentTokensIfUserIsNotAuthorized()
    {
        $query = <<<QUERY
query {
    customerPaymentTokens {
        items {
            public_hash
            details
            payment_method_code
            type
        }
    }
}
QUERY;
        $this->graphQlQuery($query);
    }

    /**
     * @magentoApiDataFixture Magento/Vault/_files/payment_tokens.php
     */
    public function testDeletePaymentToken()
    {
        $currentEmail = 'customer@example.com';
        $currentPassword = 'password';
        $tokens = $this->paymentTokenManagement->getVisibleAvailableTokens(1);
        $token = current($tokens);
        $publicHash = $token->getPublicHash();

        $query = <<<QUERY
mutation {
  deletePaymentToken(
    public_hash: "$publicHash"
  ) {
    result
  }
}
QUERY;
        $response = $this->graphQlQuery($query, [], '', $this->getCustomerAuthHeaders($currentEmail, $currentPassword));

        $this->assertTrue($response['deletePaymentToken']['result']);
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage GraphQL response contains errors: The current customer isn't authorized.
     */
    public function testDeletePaymentTokenIfUserIsNotAuthorized()
    {
        $query = <<<QUERY
mutation {
  deletePaymentToken(
    public_hash: "ksdfk392ks"
  ) {
    result
  }
}
QUERY;
        $this->graphQlQuery($query, [], '');
    }

    /**
     * @magentoApiDataFixture Magento/Vault/_files/payment_tokens.php
     * @expectedException \Exception
     * @expectedExceptionMessage GraphQL response contains errors: Token could not be found by public hash: ksdfk392ks
     */
    public function testDeletePaymentTokenInvalidPublicHash()
    {
        $currentEmail = 'customer@example.com';
        $currentPassword = 'password';

        $query = <<<QUERY
mutation {
  deletePaymentToken(
    public_hash: "ksdfk392ks"
  ) {
    result
  }
}
QUERY;
        $this->graphQlQuery($query, [], '', $this->getCustomerAuthHeaders($currentEmail, $currentPassword));
    }

    /**
     * @param string $email
     * @param string $password
     * @return array
     */
    private function getCustomerAuthHeaders(string $email, string $password): array
    {
        $customerToken = $this->customerTokenService->createCustomerAccessToken($email, $password);
        return ['Authorization' => 'Bearer ' . $customerToken];
    }
}
