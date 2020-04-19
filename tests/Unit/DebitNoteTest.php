<?php

namespace Tests\Unit;

use Carbon\Carbon;

use Illuminate\Support\Facades\Auth;

use Ekmungai\IFRS\Tests\TestCase;

use Ekmungai\IFRS\Models\Account;
use Ekmungai\IFRS\Models\Balance;
use Ekmungai\IFRS\Models\Currency;
use Ekmungai\IFRS\Models\Ledger;
use Ekmungai\IFRS\Models\LineItem;

use Ekmungai\IFRS\Transactions\DebitNote;

use Ekmungai\IFRS\Exceptions\LineItemAccount;
use Ekmungai\IFRS\Exceptions\MainAccount;

class DebitNoteTest extends TestCase
{
    /**
     * Test Creating DebitNote Transaction
     *
     * @return void
     */
    public function testCreateDebitNoteTransaction()
    {
        $supplierAccount = factory(Account::class)->create([
            'account_type' => Account::PAYABLE,
        ]);

        $debitNote = DebitNote::new($supplierAccount, Carbon::now(), $this->faker->word);
        $debitNote->setDate(Carbon::now());
        $debitNote->save();

        $this->assertEquals($debitNote->getAccount()->name, $supplierAccount->name);
        $this->assertEquals($debitNote->getAccount()->description, $supplierAccount->description);
        $this->assertEquals($debitNote->getTransactionNo(), "DN0".$this->period->period_count."/0001");
    }

    /**
     * Test Posting DebitNote Transaction
     *
     * @return void
     */
    public function testPostDebitNoteTransaction()
    {
        $debitNote = DebitNote::new(
            factory('Ekmungai\IFRS\Models\Account')->create([
                'account_type' => Account::PAYABLE,
            ]),
            Carbon::now(),
            $this->faker->word
        );

        $lineItem = factory(LineItem::class)->create([
            "amount" => 100,
            "vat_id" => factory('Ekmungai\IFRS\Models\Vat')->create([
                "rate" => 16
            ])->id,
            "account_id" => factory('Ekmungai\IFRS\Models\Account')->create([
                "account_type" => Account::DIRECT_EXPENSE
            ])->id,
        ]);
        $debitNote->addLineItem($lineItem);

        $debitNote->post();

        $debit = Ledger::where("entry_type", Balance::D)->get()[0];
        $credit = Ledger::where("entry_type", Balance::C)->get()[0];

        $this->assertEquals($debit->post_account, $debitNote->getAccount()->id);
        $this->assertEquals($debit->folio_account, $lineItem->account_id);
        $this->assertEquals($credit->folio_account, $debitNote->getAccount()->id);
        $this->assertEquals($credit->post_account, $lineItem->account_id);
        $this->assertEquals($debit->amount, 100);
        $this->assertEquals($credit->amount, 100);

        $vat_debit = Ledger::where("entry_type", Balance::D)->get()[1];
        $vat_credit = Ledger::where("entry_type", Balance::C)->get()[1];

        $this->assertEquals($vat_debit->post_account, $debitNote->getAccount()->id);
        $this->assertEquals($vat_debit->folio_account, $lineItem->vat_account_id);
        $this->assertEquals($vat_credit->folio_account, $debitNote->getAccount()->id);
        $this->assertEquals($vat_credit->post_account, $lineItem->vat_account_id);
        $this->assertEquals($vat_debit->amount, 16);
        $this->assertEquals($vat_credit->amount, 16);

        $this->assertEquals($debitNote->getAmount(), 116);
    }

    /**
     * Test Debit Note Line Item Account.
     *
     * @return void
     */
    public function testDebitNoteLineItemAccount()
    {
        $debitNote = DebitNote::new(
            factory('Ekmungai\IFRS\Models\Account')->create([
                'account_type' => Account::PAYABLE,
            ]),
            Carbon::now(),
            $this->faker->word
        );
        $this->expectException(LineItemAccount::class);
        $this->expectExceptionMessage(
            "Debit Note LineItem Account must be of type "
            ."Operating Expense, Direct Expense, Overhead Expense, "
            ."Other Expense, Non Current Asset, Current Asset, Inventory"
        );

        $lineItem = factory(LineItem::class)->create([
            "amount" => 100,
            "vat_id" => factory('Ekmungai\IFRS\Models\Vat')->create([
                "rate" => 16
            ])->id,
            "account_id" => factory('Ekmungai\IFRS\Models\Account')->create([
                "account_type" => Account::RECONCILIATION
            ])->id,
        ]);
        $debitNote->addLineItem($lineItem);

        $debitNote->post();
    }

    /**
     * Test Debit Note Main Account.
     *
     * @return void
     */
    public function testDebitNoteMainAccount()
    {
        $debitNote = DebitNote::new(
            factory('Ekmungai\IFRS\Models\Account')->create([
                'account_type' => Account::RECONCILIATION,
            ]),
            Carbon::now(),
            $this->faker->word
        );
        $this->expectException(MainAccount::class);
        $this->expectExceptionMessage('Debit Note Main Account must be of type Payable');

        $lineItem = factory(LineItem::class)->create([
            "amount" => 100,
            "vat_id" => factory('Ekmungai\IFRS\Models\Vat')->create([
                "rate" => 16
            ])->id,
            "account_id" => factory('Ekmungai\IFRS\Models\Account')->create([
                "account_type" => Account::RECONCILIATION
            ])->id,
        ]);
        $debitNote->addLineItem($lineItem);

        $debitNote->post();
    }

    /**
     * Test Debit Note Find.
     *
     * @return void
     */
    public function testDebitNoteFind()
    {
        $account = factory(Account::class)->create([
            'account_type' => Account::PAYABLE,
        ]);
        $transaction = DebitNote::new(
            $account,
            Carbon::now(),
            $this->faker->word
        );
        $transaction->save();

        $found = DebitNote::find($transaction->getId());
        $this->assertEquals($found->getTransactionNo(), $transaction->getTransactionNo());
    }

    /**
     * Test Debit Note Fetch.
     *
     * @return void
     */
    public function testDebitNoteFetch()
    {
        $account = factory(Account::class)->create([
            'account_type' => Account::PAYABLE,
        ]);
        $transaction = DebitNote::new(
            $account,
            Carbon::now(),
            $this->faker->word
        );
        $transaction->save();

        $account2 = factory(Account::class)->create([
            'account_type' => Account::PAYABLE,
        ]);
        $transaction2 = DebitNote::new(
            $account2,
            Carbon::now()->addWeeks(2),
            $this->faker->word
        );
        $transaction2->save();

        // startTime Filter
        $this->assertEquals(count(DebitNote::fetch()), 2);
        $this->assertEquals(count(DebitNote::fetch(Carbon::now()->addWeek())), 1);
        $this->assertEquals(count(DebitNote::fetch(Carbon::now()->addWeeks(3))), 0);

        // endTime Filter
        $this->assertEquals(count(DebitNote::fetch(null, Carbon::now())), 1);
        $this->assertEquals(count(DebitNote::fetch(null, Carbon::now()->subDay())), 0);

        // Account Filter
        $account3 = factory(Account::class)->create([
            'account_type' => Account::PAYABLE,
        ]);
        $this->assertEquals(count(DebitNote::fetch(null, null, $account)), 1);
        $this->assertEquals(count(DebitNote::fetch(null, null, $account2)), 1);
        $this->assertEquals(count(DebitNote::fetch(null, null, $account3)), 0);

        // Currency Filter
        $currency = factory(Currency::class)->create();
        $this->assertEquals(count(DebitNote::fetch(null, null, null, Auth::user()->entity->currency)), 2);
        $this->assertEquals(count(DebitNote::fetch(null, null, null, $currency)), 0);
    }
}
