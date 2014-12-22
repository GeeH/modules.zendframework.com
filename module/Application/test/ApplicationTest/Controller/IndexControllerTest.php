<?php
namespace ApplicationTest\Controller;

use Application\Module;
use UglyTesting\AbstractControllerTestCase;
use Zend\Db\Adapter\Adapter;
use Zend\Paginator\Paginator;
use Zend\View\Model\ViewModel;


class IndexControllerTest extends AbstractControllerTestCase
{
    public function setUp()
    {
        $this->givenTestsController('Application/Controller/Index');
    }

    public function testIndexAction()
    {

        $mockPaginator = $this->getMockBuilder(Paginator::class)
            ->disableOriginalConstructor();

        $mock = $this->getMock(Module::class, ['pagination']);
        $mock->expects($this->once())
            ->method('pagination')
            ->willReturn($mockPaginator);


        $this->givenMockedClass('moduleMapper', $mock)
            ->givenUrl('/')
            ->shouldRouteTo('home')
            ->shouldRunAction('index')
            ->shouldReturnA(ViewModel::class);
    }
}
