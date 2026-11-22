# Shop admin guide

This guide shows you how to run your online bookstore day to day. You do everything in the shop's admin area (called wp-admin). Log in at `https://your-shop-address/wp-admin` with the username and password we gave you.

The menu is on the left side of every admin page. Menu paths in this guide are written like **Products › Add new product**, which means: click **Products**, then click **Add new product**.

Contents:

1. [Add a book](#1-add-a-book)
2. [Change a price or put a book on sale](#2-change-a-price-or-put-a-book-on-sale)
3. [Update stock](#3-update-stock)
4. [Process an order](#4-process-an-order)
5. [Create a discount code](#5-create-a-discount-code)
6. [Change the featured books on the home page](#6-change-the-featured-books-on-the-home-page)
7. [Change delivery rates](#7-change-delivery-rates)

---

## 1. Add a book

1. Go to **Products › Add new product**.
2. Type the book's title at the top, and a description in the large box below it. The description is shown on the book's page.
3. In the **Product data** box, on the **General** tab, fill in:
   - **Regular price (Rs)**: the price in rupees, for example `450`.
   - **Author** and **Page count**: shown on the book's page.
4. Click the **Inventory** tab. Tick **Track stock quantity for this product**, then enter the **Quantity** you have. Optionally enter an **SKU** (your own code for the book, or the ISBN).
5. Click the **Attributes** tab. This is how customers find the book when they browse by age, language and type. For each of **Age group**, **Language** and **Book type**:
   1. Choose it from the **Add existing** drop-down (the list is on the Attributes tab).
   2. Click in the **Value(s)** box and choose one or more values (for example Age group: `4–7`; Language: `Nepali`; Book type: `Story`).
   3. Click **Save attributes**.
6. On the right, click **Set product image** and upload the cover photo. Use **Add product gallery images** for extra photos (back cover, inside pages).
7. Under **Product categories**, tick a category if you use them.
8. Click **Publish**. The book now appears in the shop.

![The Add new product page](admin-guide/01-add-book.png)

![Price, author and page count on the General tab](admin-guide/02-book-details.png)

Tip: to add a book that is similar to an existing one, go to **Products › All Products**, hover over the book's name until the links appear under it, and click **Duplicate**, then change the details.

## 2. Change a price or put a book on sale

1. Go to **Products › All Products**.
2. Find the book (use the search box at the top right) and click its name.
3. On the **General** tab of the **Product data** box:
   - To change the normal price, edit **Regular price (Rs)**.
   - To put the book on sale, type the discounted price into **Sale price (Rs)**. Customers see the old price crossed out and the sale price next to it.
   - To run the sale only for certain dates, click **Schedule** under the sale price and choose the start and end dates.
   - To end a sale, empty the **Sale price (Rs)** box.
4. Click **Update**.

## 3. Update stock

When new copies arrive, or when you sell books outside the website:

1. Go to **Products › All Products**. The **Stock** column shows how many copies are left for every book.
2. Click the book's name, then the **Inventory** tab in the **Product data** box.
3. Change **Quantity**.
4. Click **Update**.

What the shop does for you:

- When a customer orders a book, the quantity goes down automatically.
- When a book reaches 0, it shows as **Out of stock** and cannot be bought.
- When a book is down to 3 copies or fewer, the shop emails the admin address so you can re-order in time.
- To stop selling a book without deleting it, set **Stock status** to **Out of stock** on the Inventory tab.

![Stock settings on the Inventory tab](admin-guide/03-stock.png)

## 4. Process an order

When a customer places an order, you receive an email, and the order appears under **WooCommerce › Orders**. An order moves through these statuses:

| Status | Meaning |
| --- | --- |
| **Pending payment** | Customer chose eSewa but has not finished paying yet. Do not pack it. |
| **Processing** | The order is confirmed (paid by eSewa, or cash on delivery). Time to pack it. |
| **Packed** | The books are packed and ready for the courier. |
| **Shipped** | Handed to the courier or rider. |
| **Delivered** | The customer has received the books. |
| **On hold** | Needs your attention before going on. |
| **Cancelled** / **Refunded** / **Failed** | Order did not go ahead. |

![The orders list](admin-guide/04-orders.png)

Step by step:

1. Go to **WooCommerce › Orders** and click an order to open it. You see the customer's name, phone number, delivery address and the books ordered. The payment method is shown under the order number, for example "Payment via Cash on delivery".
2. Pack the books. Then change the status:
   1. In the **Status** drop-down under **General** (at the top of the order), choose **Packed**.
   2. Click the blue **Update** button at the top right, in the **Order actions** box.
3. When the courier takes the parcel, set the status to **Shipped** and click **Update**.
4. When the customer has received it, set the status to **Delivered** and click **Update**.

![An open order with the Status drop-down and Update button](admin-guide/09-order.png)

The customer gets an email when the order is first confirmed and when it is marked **Delivered**.

To change many orders at once: on the **WooCommerce › Orders** list, tick the orders, choose **Change status to packed**, **Change status to shipped** or **Change status to completed** from **Bulk actions**, and click **Apply**. ("Completed" is the same as **Delivered**; the bulk menu uses WooCommerce's original name for it.)

**Cash on delivery (COD) orders.** The customer pays the rider when the books arrive, so a COD order shows as **Processing** from the start, even though no money has been received. Pack and ship it as normal. When the rider confirms the cash was collected and the books were handed over, set the order to **Delivered**. That is how you confirm payment for a COD order. If the customer refuses the parcel, set it to **Cancelled**, and the books go back into stock.

**eSewa orders.** The order moves to **Processing** by itself once eSewa confirms the payment. If a customer started paying but never finished, the order stays on **Pending payment** (or becomes **Failed** if they cancelled on the eSewa page). The customer can place a new order and choose cash on delivery instead.

**Automatic eSewa check.** Some customers pay on eSewa but close the tab before they come back to the site. Every 5 minutes the website asks eSewa about unpaid eSewa orders placed between 10 minutes and 48 hours ago, including every earlier payment attempt on the same order. If eSewa says one was paid, the order moves to **Processing** by itself (stock is reduced and the confirmation email goes out, once). The website also asks eSewa before it cancels an unpaid eSewa order for being too old, so a paid order is not cancelled. For paid eSewa orders where the customer tried more than once, it also checks the other attempts, in case the customer paid twice. The order notes tell you what happened:

| Note starts with | What it means | What to do |
|---|---|---|
| "Payment confirmed by eSewa status check (customer did not return to the site)" | eSewa confirmed the payment after the customer left. The note has eSewa's reference. | Nothing. Pack it like any paid order. |
| "Automatic eSewa payment check for transaction … returned NOT_FOUND" (or CANCELED) | eSewa has no completed payment for that attempt. | Nothing. Customer did not pay. The order is cancelled automatically later, or the customer pays again. |
| "Automatic eSewa payment check for transaction … FULL_REFUND" | The payment was refunded in full in eSewa. | Check the eSewa merchant portal if you did not expect a refund. |
| "Automatic eSewa payment check for transaction … PARTIAL_REFUND. eSewa still holds part of this payment" | Only part of the payment was refunded; eSewa still holds the rest. The order may still be cancelled automatically. | Check the eSewa merchant portal, then refund the rest or complete the order by hand. |
| "Automatic eSewa payment check … amount mismatch" | eSewa has a completed payment, but for a different amount than the order total (for example, the order was edited after payment). **The order is never cancelled automatically.** | Compare the amounts in the eSewa merchant portal, then set the order to **Processing**, refund the difference, or refund and cancel it. |
| "eSewa took a second payment for this order — refund ref … in the eSewa merchant portal" | The order is paid, but the customer also paid on another attempt (for example, they paid, lost the page and paid again). | Find the reference (and the transaction UUID in the note) in the eSewa merchant portal and refund that second payment. Keep the order as it is. |

An eSewa order that has no note yet may simply be waiting: eSewa said "pending", or could not be reached, and the website will ask again in 5 minutes.

**Orders the website will never cancel by itself.** An unpaid eSewa order that eSewa still reports as **pending**, and an order with an "amount mismatch" note, stay on **Pending payment** (their books stay reserved) until you act. After 48 hours the website stops checking them. Check the transaction in the eSewa merchant portal and either complete the order (set it to **Processing**) or cancel it (and refund in eSewa if money was taken).

For an unpaid eSewa order **older than 48 hours**, the website has stopped checking. Before you cancel such an order or put its books back into stock, log in to the **eSewa merchant portal** and search for a transaction for that order (the order number and amount). If eSewa shows the money was received, treat the order as paid: set it to **Processing** and pack it, or refund the customer from the eSewa portal. Only cancel the order if eSewa shows no completed payment.

If an order has a note starting "eSewa reported a successful payment for this order after it was switched to another payment method", the customer paid with eSewa and then changed the order to cash on delivery (the note ends "signed by eSewa" or "confirmed by eSewa status check" when eSewa itself reported it). Look up the transaction in the eSewa merchant portal using the transaction UUID in the note, and refund the eSewa payment (or collect no cash on delivery) so the customer does not pay twice. The website adds this note once per payment.

**Customers can track their order** on the "Track your order" page of the website, using their order number and email.

## 5. Create a discount code

1. Go to **WooCommerce › Coupons** and click **Add coupon**.
2. In **Coupon code** type the code customers will enter (for example `EID10`). Optionally add a description for yourself.
3. On the **General** tab:
   - **Discount type**: choose **Percentage discount** (for example 10%) or **Fixed cart discount** (for example Rs 100 off the whole order).
   - **Coupon amount**: the percentage or rupee amount.
   - **Coupon expiry date**: when the code stops working (optional but recommended).
4. On the **Usage restriction** tab you can set a **Minimum spend** for the code to work.
   (The tabs are **General**, **Usage restriction** and **Usage limits**.)
5. On the **Usage limits** tab you can limit how many times the code can be used in total (**Usage limit per coupon**) and by each customer (**Usage limit per user**).
6. Click **Publish**.

Customers enter the code in the cart or at checkout. To stop a code, open it in **WooCommerce › Coupons** and move it to the trash, or set an expiry date in the past.

![The Add coupon page](admin-guide/05-coupon.png)

## 6. Change the featured books on the home page

The home page has a section called **Our favourites** that shows books you have marked as featured (starred). It shows up to eight. The **New arrivals** section next to it fills in automatically with your newest books; you do not need to manage that one. The **Best sellers** section is automatic too: it shows the eight in-stock books that have sold the most copies, so there is nothing to set up for it.

1. Go to **Products › All Products**.
2. Look at the star column (the column with a star at the top). A filled blue star means the book is featured. An empty star means it is not.
3. Click a star to switch it on or off. The change is saved straight away.

If more than eight books are starred, the home page shows the newest eight of them.

![Star column in the product list](admin-guide/06-products.png)

## 7. Change delivery rates

Delivery has two prices, depending on where the customer lives:

- **Kathmandu Valley** (Kathmandu, Lalitpur and Bhaktapur districts)
- **Rest of Nepal**

Orders above a set amount get free delivery. The rates you see in the shop at first are placeholders (Valley Rs 100, Rest of Nepal Rs 200, free delivery over Rs 2,000) until you give us your real courier rates.

To change them:

1. Go to **WooCommerce › Settings**, then the **Shipping** tab.
2. Click **Edit** next to the zone you want to change (**Kathmandu Valley** or **Rest of Nepal**).
3. Next to **Delivery**, click **Edit**. Change **Cost** to the new rate in rupees, then click **Save changes**.
4. To change the free-delivery amount, click **Edit** next to **Free delivery** in the same zone and change **Minimum order amount**. Then click **Save changes**.
5. Do the same in the other zone. Use the same free-delivery amount in both zones, so customers everywhere get the same offer.

Always check the result by adding a book to the cart on your website and going to checkout.

When an order qualifies for free delivery, the checkout offers **Free delivery** and hides the paid **Delivery** option. Any other option you add to the zone, such as local pickup, still shows. Orders below the amount see the paid **Delivery** price instead.

![Shipping zones](admin-guide/07-shipping.png)

---

## Need help?

If something looks wrong, do not delete anything. Take a screenshot and send it to us with a short description of what you were doing.
