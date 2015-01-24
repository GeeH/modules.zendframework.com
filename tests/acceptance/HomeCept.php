<?php 
$I = new AcceptanceTester($scenario);
$I->wantTo('View the home page');
$I->amOnPage('/');
$I->see('Welcome to the ZF2 Modules Site');
$I->click('Zf2Acl');
$I->see('Zf2Acl');

