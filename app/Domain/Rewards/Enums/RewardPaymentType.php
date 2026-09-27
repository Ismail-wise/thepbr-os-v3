<?php

declare(strict_types=1);

namespace App\Domain\Rewards\Enums;

enum RewardPaymentType: string
{
    case SalaryServiceFee = 'salary_service_fee';
    case Reimbursement = 'reimbursement';
    case Bonus = 'bonus';
    case LoanRepayment = 'loan_repayment';
    case ProfitDistribution = 'profit_distribution';
}
