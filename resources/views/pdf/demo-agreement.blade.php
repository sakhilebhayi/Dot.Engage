<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>{{ $title }}</title>
<style>
    @page { margin: 64px 64px 72px; }
    body { font-family: 'DejaVu Serif', 'Times New Roman', serif; font-size: 11.5px; line-height: 1.6; color: #111; }
    h1 { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 20px; text-align: center; margin: 0 0 6px; }
    .center { text-align: center; color: #444; margin-bottom: 26px; font-size: 11px; }
    h2 { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 12px; margin: 22px 0 6px; }
    p { margin: 0 0 9px; text-align: justify; }
    .cl { margin-left: 0; }
    .break { page-break-before: always; }
    table { width: 100%; border-collapse: collapse; margin: 8px 0 14px; font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 10px; }
    td, th { border: 1px solid #999; padding: 5px 8px; text-align: left; }
    .exec td { height: 70px; vertical-align: bottom; }
</style></head><body>
<h1>{{ strtoupper($title) }}</h1>
<div class="center">between {{ $host }} and {{ $other }}</div>

<h2>1. Definitions</h2>
<p>1.1 "<b>Services</b>" means the refrigerated road transport of the Goods described in Schedule 1, including loading supervision, temperature logging and delivery to the consignee named on the waybill.</p>
<p>1.2 "<b>Goods</b>" means fresh produce that must be carried at a controlled temperature between 2 and 8 degrees Celsius.</p>
<p>1.3 "<b>Effective Date</b>" means the date on which the last party signs this agreement.</p>

<h2>2. Term</h2>
<p>2.1 This agreement starts on the Effective Date and continues for twelve (12) months unless terminated earlier under clause 9.</p>
<p>2.2 Either party may renew the agreement for a further twelve months by written notice given not less than sixty (60) days before the end of the term.</p>

<h2>3. The Services</h2>
<p>3.1 {{ $host }} shall collect the Goods from the premises named in each booking within the pickup window agreed in that booking.</p>
<p>3.2 {{ $host }} shall keep the Goods within the temperature range in clause 1.2 for the whole journey and shall give {{ $other }} the temperature log within twenty-four (24) hours of delivery.</p>
<p>3.3 {{ $other }} shall ensure the Goods are pre-cooled to 4 degrees Celsius or lower before loading.</p>

<h2>4. Fees and payment</h2>
<p>4.1 {{ $other }} shall pay the rates set out in Schedule 2. Rates are exclusive of value-added tax.</p>
<p>4.2 {{ $host }} shall invoice monthly in arrears. {{ $other }} shall pay each undisputed invoice within thirty (30) days of the invoice date.</p>
<p>4.3 Overdue amounts bear interest at the prime lending rate plus two percent per annum, calculated daily from the due date to the date of payment.</p>

<h2 class="break">5. Liability</h2>
<p>5.1 {{ $host }}'s liability for loss of or damage to the Goods in transit is limited to the invoiced value of the affected Goods, and in any event to one million rand (R1 000 000) per incident.</p>
<p>5.2 Neither party is liable for indirect or consequential loss, including loss of profit, except where caused by gross negligence or wilful misconduct.</p>

<h2>6. Insurance</h2>
<p>6.1 {{ $host }} shall maintain goods-in-transit insurance for the full term and shall provide the certificate of cover to {{ $other }} on request.</p>

<h2>7. Confidentiality</h2>
<p>7.1 Each party shall keep the other's rates, volumes and customer details confidential during the term and for three (3) years afterwards, except where disclosure is required by law.</p>

<h2>8. Data protection</h2>
<p>8.1 Each party shall process any personal information received under this agreement only for the purpose of performing it and in line with the Protection of Personal Information Act, 2013.</p>

<h2>9. Termination</h2>
<p>9.1 Either party may terminate this agreement on ninety (90) days' written notice.</p>
<p>9.2 Either party may terminate immediately by written notice if the other commits a material breach and fails to remedy it within fourteen (14) days of being asked to do so.</p>

<h2>10. General</h2>
<p>10.1 This agreement is governed by the laws of the Republic of South Africa.</p>
<p>10.2 This agreement is the whole agreement between the parties on its subject and may be changed only in writing signed by both.</p>
<p>10.3 This agreement may be signed electronically and in counterparts.</p>

<h2 class="break">Schedule 1: Services</h2>
<table><tr><th>Route</th><th>Frequency</th><th>Vehicle</th></tr>
<tr><td>Ceres to Cape Town market</td><td>Daily, Monday to Saturday</td><td>12-pallet reefer</td></tr>
<tr><td>Ceres to Durban</td><td>Twice weekly</td><td>28-pallet reefer</td></tr></table>

<h2>Schedule 2: Rates</h2>
<table><tr><th>Route</th><th>Rate per pallet</th><th>Minimum per trip</th></tr>
<tr><td>Ceres to Cape Town market</td><td>R 310.00</td><td>R 2 480.00</td></tr>
<tr><td>Ceres to Durban</td><td>R 940.00</td><td>R 11 280.00</td></tr></table>

<h2>Signed</h2>
<p>The parties sign this agreement on the dates shown beside their signatures.</p>
<table class="exec"><tr><th style="width:50%">For {{ $host }}</th><th>For {{ $other }}</th></tr>
<tr><td>Name:<br>Date:</td><td>Name:<br>Date:</td></tr></table>
</body></html>
