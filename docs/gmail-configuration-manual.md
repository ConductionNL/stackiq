# Gmail Configuration Manual for Software Catalog

This manual provides step-by-step instructions for configuring Gmail to send emails through the Software Catalog application using Symfony Mailer.

## Prerequisites

- Active Gmail account
- Software Catalog application with admin access
- Gmail account with 2-Factor Authentication enabled (required for App Passwords)

## Step 1: Enable 2-Factor Authentication on Gmail

1. **Go to Google Account Settings**
   - Visit [myaccount.google.com](https://myaccount.google.com)
   - Click on "Security" in the left sidebar

2. **Enable 2-Step Verification**
   - Under "Signing in to Google", click "2-Step Verification"
   - Follow the setup wizard to enable 2FA using your phone number
   - Complete the verification process

## Step 2: Generate Gmail App Password

1. **Access App Passwords**
   - In Google Account Settings → Security
   - Under "Signing in to Google", click "App passwords"
   - You may need to sign in again

2. **Generate New App Password**
   - Click "Select app" dropdown → Choose "Mail"
   - Click "Select device" dropdown → Choose "Other (Custom name)"
   - Enter a name like "Software Catalog" or "Nextcloud"
   - Click "Generate"

3. **Save the App Password**
   - Copy the 16-character app password (format: xxxx xxxx xxxx xxxx)
   - **Important:** Save this password securely - you won't be able to see it again
   - Remove spaces when entering it in the Software Catalog

## Step 3: Configure Software Catalog Email Settings

### Access Email Configuration

1. **Login to Software Catalog**
   - Access your Nextcloud instance
   - Navigate to Apps → Software Catalog
   - Go to Settings (admin panel)

2. **Open Email Configuration**
   - Scroll to the "Email Configuration" section
   - Click to expand email settings

### Configure SMTP Settings

Fill in the following settings:

| Setting | Value | Description |
|---------|--------|-------------|
| **Email Enabled** | ✅ Checked | Enable email functionality |
| **Transport Type** | `SMTP Server` | Select SMTP from dropdown |
| **SMTP Host** | `smtp.gmail.com` | Gmail's SMTP server |
| **SMTP Port** | `587` | Standard TLS port (recommended) |
| **Encryption** | `TLS` | Transport Layer Security |
| **SMTP Username** | `your-email@gmail.com` | Your full Gmail address |
| **SMTP Password** | `xxxxxxxxxxxx` | The 16-character App Password (no spaces) |

### Configure Sender Information

| Setting | Value | Example |
|---------|--------|---------|
| **Sender Email** | `your-email@gmail.com` | `softwarecatalog@yourorg.com` |
| **Sender Name** | `Your Organization Name` | `Software Catalog Team` |

### Configure Email Types (Optional)

Enable the types of emails you want to send:

- ✅ **Organization Registration Emails** - Welcome emails for new organizations
- ✅ **Organization Activation Emails** - Notification when organizations are activated
- ✅ **User Creation Emails** - Welcome emails for new users
- ✅ **User Password Emails** - Login credentials for new users

### Test Configuration (Optional)

| Setting | Value | Description |
|---------|--------|-------------|
| **Test Receiver Override** | `test@example.com` | All emails go here during testing |

## Step 4: Test Email Configuration

1. **Send Test Email**
   - Scroll to "Test Email" section
   - Enter a test email address
   - Click "Send Test Email"

2. **Verify Success**
   - Check the test email inbox
   - Look for email from your configured sender
   - Verify email content and formatting

## Step 5: Alternative Port Configuration

If port 587 doesn't work (some networks block it), try:

| Setting | Alternative Value |
|---------|-------------------|
| **SMTP Port** | `465` |
| **Encryption** | `SSL` |

## Troubleshooting

### Common Issues and Solutions

#### 1. Authentication Failed
**Error:** "Authentication failed" or "Invalid credentials"

**Solutions:**
- Verify 2FA is enabled on Gmail account
- Generate a new App Password
- Ensure no spaces in the App Password
- Check username is full email address

#### 2. Connection Timeout
**Error:** "Connection timed out" or "Could not connect to SMTP host"

**Solutions:**
- Try port 465 with SSL encryption
- Check firewall settings
- Verify network allows SMTP connections
- Contact your system administrator

#### 3. SSL/TLS Errors
**Error:** "SSL connection failed" or certificate errors

**Solutions:**
- Switch between TLS (port 587) and SSL (port 465)
- Ensure server has updated SSL certificates
- Check system time is correct

#### 4. Emails Not Sending
**Error:** No error but emails don't arrive

**Solutions:**
- Check spam/junk folders
- Verify sender email is not blacklisted
- Enable test receiver override for debugging
- Check Gmail account sending limits

### Gmail Sending Limits

Gmail has daily sending limits:
- **Personal Gmail:** 500 emails per day
- **Google Workspace:** 2,000 emails per day

For higher volumes, consider:
- Google Workspace upgrade
- Professional email service (SendGrid, Mailgun, etc.)
- Multiple Gmail accounts with load balancing

## Security Best Practices

1. **App Password Security**
   - Store App Passwords securely
   - Don't share App Passwords
   - Revoke unused App Passwords regularly

2. **Email Content**
   - Use professional email templates
   - Include unsubscribe options where appropriate
   - Follow email marketing regulations (GDPR, CAN-SPAM)

3. **Monitoring**
   - Monitor email delivery rates
   - Check for bounced emails
   - Review Gmail account activity regularly

## Advanced Configuration

### Custom Email Templates

The Software Catalog supports custom email templates:

1. **Template Types:**
   - Organization Registration
   - Organization Activation  
   - User Creation
   - User Password

2. **Template Variables:**
   - `{{ organization.name }}` - Organization name
   - `{{ user.name }}` - User name
   - `{{ user.email }}` - User email
   - `{{ user.password }}` - Generated password

### Multiple Email Accounts

For high-volume sending, configure multiple Gmail accounts:

1. Create additional Gmail accounts
2. Generate App Passwords for each
3. Use round-robin or load balancing
4. Monitor sending quotas across accounts

## Support

### Getting Help

1. **Check Logs**
   - Review Nextcloud logs for email errors
   - Check Software Catalog application logs

2. **Test Configuration**
   - Use built-in test email function
   - Verify all settings step-by-step

3. **Contact Support**
   - Provide error messages and logs
   - Include configuration details (without passwords)
   - Specify which step failed

### Additional Resources

- [Gmail SMTP Settings Official Documentation](https://support.google.com/mail/answer/7126229)
- [Google App Passwords Help](https://support.google.com/accounts/answer/185833)
- [Symfony Mailer Documentation](https://symfony.com/doc/current/mailer.html)

---

**Last Updated:** January 2024  
**Version:** 1.0.0  
**Compatible with:** Software Catalog v0.1.29+