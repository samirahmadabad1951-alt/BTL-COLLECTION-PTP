//work2/frontend/js/auth.js

// ============================================================
// SELLERS PAGE - USES SHARED AUTH MODULE
// ============================================================

// No need to redefine API_BASE, showToast, login, register, etc.
// They come from auth.js

// State
var selectedCategories = [];
var productsToUpload = [];
var videoUrls = [];
var selectedProductLabels = [];

// Categories
var categories = ['Personal Care', 'Kitchen', 'Home', 'Garden', 'Bags', 'Drinkware', 'Fashion', 'Electronics'];
var allLabels = ['Biodegradable', 'Organic', 'Recycled', 'Reusable', 'Solar', 'Vegan', 'Plastic-Free', 'Zero Waste'];

// ==================== CHECK USER ROLE ====================
async function checkUserSellerStatus() {
    if (!currentUser) return false;
    
    // Check if user role is already seller
    if (currentUser.role === 'seller' || currentUser.role === 'admin') {
        return { isApproved: true, isPending: false };
    }
    
    // Check localStorage for application status
    var applications = JSON.parse(localStorage.getItem('seller_applications') || '[]');
    var userApp = applications.find(function(app) { return app.email === currentUser.email; });
    if (userApp) {
        return { isApproved: userApp.status === 'approved', isPending: userApp.status === 'pending', application: userApp };
    }
    
    return { isApproved: false, isPending: false };
}

// ==================== RENDER SELLER DASHBOARD ====================
function renderSellerDashboard() {
    var container = document.getElementById('seller-dashboard');
    if (!container) return;
    
    if (!currentUser) {
        container.innerHTML = 
            '<div class="grid2" style="align-items:start; gap:2.5rem;">' +
                '<div class="rev">' +
                    '<h2 style="font-family:var(--font-d);font-size:1.875rem;font-weight:700">Apply to sell</h2>' +
                    '<p style="margin-top:.5rem;color:var(--muted-fg);margin-bottom:1.5rem">Please sign in to apply as a seller.</p>' +
                    '<div class="steps-container">' +
                        '<div class="step"><div class="step-number">1</div><h3>Sign In</h3><p>Create an account or log in</p></div>' +
                        '<div class="step"><div class="step-number">2</div><h3>Submit application</h3><p>Fill out the seller form</p></div>' +
                        '<div class="step"><div class="step-number">3</div><h3>Get verified</h3><p>We review your application (48h)</p></div>' +
                        '<div class="step"><div class="step-number">4</div><h3>Start selling</h3><p>List your products</p></div>' +
                    '</div>' +
                    '<a href="login.html" class="btn-p" style="margin-top:1rem">Sign In to Apply</a>' +
                '</div>' +
                '<div class="rev" style="transition-delay:.1s">' +
                    '<div class="application-form">' +
                        '<h2 class="form-title">Become a seller</h2>' +
                        '<p class="form-subtitle">Join our community of eco-conscious makers</p>' +
                        '<p style="color:var(--muted-fg);text-align:center;padding:2rem">Please <a href="login.html">sign in</a> to submit your seller application.</p>' +
                    '</div>' +
                '</div>' +
            '</div>';
        return;
    }
    
    // User is logged in - check seller status
    checkUserSellerStatus().then(function(status) {
        if (status.isApproved) {
            renderProductManagement(container);
        } else if (status.isPending) {
            container.innerHTML = 
                '<div class="success-message">' +
                    '<div class="success-icon gl"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg></div>' +
                    '<h3>Application Under Review! 🌱</h3>' +
                    '<p>Your seller application is being reviewed by our team. We\'ll notify you within 48 hours.</p>' +
                    '<a href="index.html" class="btn-p" style="margin-top:1rem;display:inline-block">Return to Home</a>' +
                '</div>';
        } else {
            renderApplicationForm(container);
        }
    });
}

function renderApplicationForm(container) {
    container.innerHTML = 
        '<div class="grid2" style="align-items:start; gap:2.5rem;">' +
            '<div class="rev">' +
                '<h2 style="font-family:var(--font-d);font-size:1.875rem;font-weight:700">Apply to sell</h2>' +
                '<p style="margin-top:.5rem;color:var(--muted-fg);margin-bottom:1.5rem">Tell us about your brand and upload your eco-certification.</p>' +
                '<div class="steps-container">' +
                    '<div class="step"><div class="step-number">1</div><h3>Submit application</h3><p>Fill out the form</p></div>' +
                    '<div class="step"><div class="step-number">2</div><h3>Eco-certification review</h3><p>We verify your claims (48h)</p></div>' +
                    '<div class="step"><div class="step-number">3</div><h3>List your products</h3><p>Start adding products</p></div>' +
                    '<div class="step"><div class="step-number">4</div><h3>Start shipping</h3><p>Reach customers worldwide</p></div>' +
                '</div>' +
            '</div>' +
            '<div class="rev" style="transition-delay:.1s">' +
                '<div class="application-form">' +
                    '<div id="seller-form-content">' +
                        '<h2 class="form-title">Become a seller</h2>' +
                        '<p class="form-subtitle">Join our community of eco-conscious makers</p>' +
                        '<form onsubmit="event.preventDefault(); submitSellerApplication()">' +
                            '<div class="form-grid">' +
                                '<div class="form-group"><label>Brand name *</label><input type="text" id="brand-name" required placeholder="GreenRoots Co."></div>' +
                                '<div class="form-group"><label>Email *</label><input type="email" id="brand-email" value="' + (currentUser?.email || '') + '" required placeholder="hello@brand.com"></div>' +
                                '<div class="form-group"><label>Country *</label><input type="text" id="brand-country" required placeholder="Tanzania"></div>' +
                                '<div class="form-group"><label>Website</label><input type="url" id="brand-website" placeholder="https://"></div>' +
                            '</div>' +
                            '<div class="form-group"><label>Product categories *</label><div class="category-toggles" id="category-toggles">' +
                                categories.map(function(c) { 
                                    return '<button type="button" class="cat-toggle" onclick="toggleCategory(this)">' + c + '</button>'; 
                                }).join('') +
                            '</div><input type="hidden" id="selected-categories" value=""></div>' +
                            '<div class="form-group"><label>Sustainability description *</label><textarea id="sustainability-desc" rows="4" placeholder="Tell us how your products are sustainable..."></textarea></div>' +
                            '<div class="form-group"><label>Eco-certification *</label><div class="upload-zone" onclick="document.getElementById(\'cert-file\').click()">' +
                                '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>' +
                                '<p>Click to upload PDF or image (GOTS, FSC, USDA Organic...)</p>' +
                            '</div><input type="file" id="cert-file" style="display:none" accept=".pdf,.jpg,.jpeg,.png"></div>' +
                            '<button type="submit" class="btn-submit" id="submit-btn">Submit Application</button>' +
                        '</form>' +
                    '</div>' +
                '</div>' +
            '</div>' +
        '</div>';
}

function renderProductManagement(container) {
    container.innerHTML = 
        '<div class="grid2" style="align-items:start; gap:2.5rem;">' +
            '<div class="rev">' +
                '<div class="application-form">' +
                    '<h2 class="form-title">Add New Product</h2>' +
                    '<p class="form-subtitle">List your eco-friendly products. Note: $5 admin fee will be added to your price.</p>' +
                    '<form onsubmit="event.preventDefault(); addProduct()">' +
                        '<div class="form-group"><label>Product Name *</label><input type="text" id="product-name" required placeholder="Eco Bamboo Toothbrush"></div>' +
                        '<div class="form-group"><label>Tagline</label><input type="text" id="product-tagline" placeholder="Short catchy description"></div>' +
                        '<div class="form-grid">' +
                            '<div class="form-group"><label>Price ($) *</label><input type="number" step="0.01" id="product-price" required placeholder="19.99"></div>' +
                            '<div class="form-group"><label>Eco-Score (0-10) *</label><input type="number" min="0" max="10" id="product-eco-score" required value="8"></div>' +
                            '<div class="form-group"><label>Category *</label><select id="product-category" required>' +
                                '<option value="">Select Category</option>' +
                                '<option value="Personal Care">Personal Care</option>' +
                                '<option value="Drinkware">Drinkware</option>' +
                                '<option value="Bags">Bags</option>' +
                                '<option value="Kitchen">Kitchen</option>' +
                                '<option value="Home">Home</option>' +
                                '<option value="Garden">Garden</option>' +
                                '<option value="Electronics">Electronics</option>' +
                                '<option value="Fashion">Fashion</option>' +
                            '</select></div>' +
                            '<div class="form-group"><label>Carbon Saved (kg)</label><input type="number" step="0.1" id="product-carbon" value="0"></div>' +
                        '</div>' +
                        '<div class="form-group"><label>Description</label><textarea id="product-description" rows="3" placeholder="Detailed product description..."></textarea></div>' +
                        '<div class="form-group"><label>Materials (comma separated)</label><input type="text" id="product-materials" placeholder="Bamboo, Nylon, Recycled plastic"></div>' +
                        '<div class="form-group"><label>Stock Quantity</label><input type="number" id="product-stock" value="100"></div>' +
                        '<div class="form-group"><label>Product Images</label><div class="upload-zone" onclick="document.getElementById(\'product-images\').click()">' +
                            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>' +
                            '<p>Click to upload product images (JPG, PNG)</p>' +
                        '</div><input type="file" id="product-images" style="display:none" accept="image/*" multiple></div>' +
                        '<div class="form-group"><label>Product Video URL (YouTube/Vimeo)</label><input type="url" id="video-url" placeholder="https://www.youtube.com/watch?v=..."></div>' +
                        '<div class="form-group"><label>Labels</label><div class="category-toggles" id="product-labels-toggles">' +
                            allLabels.map(function(l) { 
                                return '<button type="button" class="cat-toggle" onclick="toggleProductLabel(this)">' + l + '</button>'; 
                            }).join('') +
                        '</div><input type="hidden" id="product-labels" value=""></div>' +
                        '<button type="submit" class="btn-submit">Add Product</button>' +
                    '</form>' +
                '</div>' +
            '</div>' +
            '<div class="rev" style="transition-delay:.1s">' +
                '<div class="application-form">' +
                    '<h2 class="form-title">Your Products</h2>' +
                    '<p class="form-subtitle">Manage your existing products</p>' +
                    '<div id="seller-products-list">' +
                        '<div class="loading-spinner" style="padding:2rem"><div class="spinner"></div></div>' +
                    '</div>' +
                '</div>' +
            '</div>' +
        '</div>' +
        '<div class="product-listing-form" style="margin-top:2rem">' +
            '<h2>Bulk Upload</h2>' +
            '<p class="form-subtitle">Upload multiple products at once using CSV</p>' +
            '<div class="bulk-upload-zone" onclick="document.getElementById(\'bulk-csv\').click()">' +
                '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:2rem;height:2rem;margin:0 auto 0.5rem"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>' +
                '<p>Click to upload CSV file with product data</p>' +
                '<p style="font-size:.625rem;margin-top:.25rem">Format: name,price,category,eco_score,tagline,description,materials,stock</p>' +
            '</div>' +
            '<input type="file" id="bulk-csv" style="display:none" accept=".csv" onchange="processBulkUpload(this)">' +
        '</div>';
    
    loadSellerProducts();
}

// ==================== CATEGORY TOGGLE ====================
function toggleCategory(btn) {
    var category = btn.textContent;
    if (selectedCategories.includes(category)) {
        selectedCategories = selectedCategories.filter(function(c) { return c !== category; });
        btn.classList.remove('selected');
    } else {
        selectedCategories.push(category);
        btn.classList.add('selected');
    }
    document.getElementById('selected-categories').value = selectedCategories.join(',');
}

function toggleProductLabel(btn) {
    var label = btn.textContent;
    if (selectedProductLabels.includes(label)) {
        selectedProductLabels = selectedProductLabels.filter(function(l) { return l !== label; });
        btn.classList.remove('selected');
    } else {
        selectedProductLabels.push(label);
        btn.classList.add('selected');
    }
    document.getElementById('product-labels').value = selectedProductLabels.join(',');
}

// ==================== PRODUCT FUNCTIONS ====================
async function addProduct() {
    var name = document.getElementById('product-name')?.value;
    var price = parseFloat(document.getElementById('product-price')?.value);
    var ecoScore = parseInt(document.getElementById('product-eco-score')?.value);
    var category = document.getElementById('product-category')?.value;
    
    if (!name || !price || !ecoScore || !category) {
        showToast('Please fill in all required fields', 'error');
        return;
    }
    
    var finalPrice = price + 5;
    
    var productData = {
        name: name,
        tagline: document.getElementById('product-tagline')?.value || '',
        description: document.getElementById('product-description')?.value || '',
        price: finalPrice,
        category: category,
        eco_score: ecoScore,
        carbon_saved: parseFloat(document.getElementById('product-carbon')?.value) || 0,
        materials: (document.getElementById('product-materials')?.value || '').split(',').map(function(s) { return s.trim(); }).filter(function(s) { return s; }),
        stock: parseInt(document.getElementById('product-stock')?.value) || 0,
        labels: selectedProductLabels,
        video_url: document.getElementById('video-url')?.value || '',
        seller_id: currentUser?.id,
        status: 'pending'
    };
    
    try {
        var response = await fetch(API_BASE + 'products.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(productData)
        });
        var data = await response.json();
        
        if (data.success) {
            showToast('Product submitted for review! Final price: $' + finalPrice.toFixed(2), 'success');
            // Clear form
            document.getElementById('product-name').value = '';
            document.getElementById('product-tagline').value = '';
            document.getElementById('product-price').value = '';
            document.getElementById('product-description').value = '';
            document.getElementById('product-materials').value = '';
            document.getElementById('product-carbon').value = '0';
            document.getElementById('product-stock').value = '100';
            document.getElementById('video-url').value = '';
            selectedProductLabels = [];
            document.querySelectorAll('#product-labels-toggles .cat-toggle').forEach(function(btn) {
                btn.classList.remove('selected');
            });
            loadSellerProducts();
        } else {
            showToast(data.message, 'error');
        }
    } catch (error) {
        showToast('Failed to add product', 'error');
    }
}

async function loadSellerProducts() {
    var container = document.getElementById('seller-products-list');
    if (!container) return;
    
    try {
        var response = await fetch(API_BASE + 'products.php');
        var data = await response.json();
        
        if (data.success && data.products && data.products.length > 0) {
            var sellerProducts = data.products.filter(function(p) { return p.seller_id == currentUser?.id; });
            if (sellerProducts.length > 0) {
                container.innerHTML = '<div class="product-grid">' + sellerProducts.map(function(p) {
                    var statusClass = p.status === 'active' ? 'active' : (p.status === 'pending' ? 'pending' : 'inactive');
                    var statusText = p.status === 'active' ? '✓ Active' : (p.status === 'pending' ? '⏳ Pending Review' : '✗ Rejected');
                    return '<div class="product-item">' +
                        '<div class="remove-product" onclick="deleteProduct(' + p.id + ')">×</div>' +
                        '<strong>' + escapeHtml(p.name) + '</strong><br>' +
                        '<span style="font-size:.75rem;color:var(--muted-fg)">Your price: $' + (parseFloat(p.price) - 5).toFixed(2) + '</span><br>' +
                        '<span style="font-size:.75rem;color:var(--primary)">Final price: $' + parseFloat(p.price).toFixed(2) + '</span><br>' +
                        '<span style="font-size:.75rem" class="status-badge ' + statusClass + '">' + statusText + '</span>' +
                    '</div>';
                }).join('') + '</div>';
            } else {
                container.innerHTML = '<div class="empty-state"><p>No products yet. Add your first product above!</p></div>';
            }
        } else {
            container.innerHTML = '<div class="empty-state"><p>No products yet. Add your first product above!</p></div>';
        }
    } catch (error) {
        container.innerHTML = '<div class="empty-state"><p>Unable to load products. Please try again.</p></div>';
    }
}

async function deleteProduct(productId) {
    if (!confirm('Are you sure you want to delete this product?')) return;
    
    try {
        var response = await fetch(API_BASE + 'products.php?id=' + productId, { method: 'DELETE' });
        var data = await response.json();
        if (data.success) {
            showToast('Product deleted', 'success');
            loadSellerProducts();
        } else {
            showToast(data.message, 'error');
        }
    } catch (error) {
        showToast('Failed to delete product', 'error');
    }
}

function processBulkUpload(input) {
    var file = input.files[0];
    if (!file) return;
    
    var reader = new FileReader();
    reader.onload = function(e) {
        var content = e.target.result;
        var lines = content.split('\n');
        var products = [];
        
        for (var i = 1; i < lines.length; i++) {
            if (!lines[i].trim()) continue;
            var values = lines[i].split(',');
            if (values.length >= 4) {
                products.push({
                    name: values[0].trim(),
                    price: parseFloat(values[1]),
                    category: values[2].trim(),
                    eco_score: parseInt(values[3]) || 8,
                    tagline: values[4]?.trim() || '',
                    description: values[5]?.trim() || '',
                    materials: values[6]?.trim() ? values[6].trim().split(';') : [],
                    stock: parseInt(values[7]) || 100
                });
            }
        }
        
        if (products.length > 0) {
            showToast('Found ' + products.length + ' products. Submitting...', 'success');
            products.forEach(function(p) {
                addBulkProduct(p);
            });
        } else {
            showToast('No valid products found in CSV', 'error');
        }
    };
    reader.readAsText(file);
    input.value = '';
}

async function addBulkProduct(p) {
    try {
        await fetch(API_BASE + 'products.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                name: p.name,
                price: p.price + 5,
                category: p.category,
                eco_score: p.eco_score,
                tagline: p.tagline,
                description: p.description,
                materials: p.materials,
                stock: p.stock,
                seller_id: currentUser?.id,
                status: 'pending'
            })
        });
    } catch (error) {
        console.error('Failed to add bulk product:', error);
    }
}

function escapeHtml(str) { if (!str) return ''; return str.replace(/[&<>]/g, function(m) { if (m === '&') return '&amp;'; if (m === '<') return '&lt;'; if (m === '>') return '&gt;'; return m; }); }

// ==================== SELLER APPLICATION ====================
async function submitSellerApplication() {
    var brandName = document.getElementById('brand-name')?.value;
    var brandEmail = document.getElementById('brand-email')?.value;
    var brandCountry = document.getElementById('brand-country')?.value;
    var brandWebsite = document.getElementById('brand-website')?.value;
    var sustainabilityDesc = document.getElementById('sustainability-desc')?.value;
    
    if (!brandName || !brandEmail || !brandCountry || !sustainabilityDesc) {
        showToast('Please fill in all required fields', 'error');
        return;
    }
    
    if (selectedCategories.length === 0) {
        showToast('Please select at least one product category', 'error');
        return;
    }
    
    var fileInput = document.getElementById('cert-file');
    var certificationFile = '';
    
    if (fileInput.files.length > 0) {
        certificationFile = fileInput.files[0].name;
    } else {
        showToast('Please upload your eco-certification', 'error');
        return;
    }
    
    var applicationData = {
        brand_name: brandName,
        email: brandEmail,
        country: brandCountry,
        website: brandWebsite,
        categories: selectedCategories,
        sustainability_description: sustainabilityDesc,
        certification_file: certificationFile,
        submitted_by: currentUser?.id || null,
        submitted_at: new Date().toISOString(),
        status: 'pending'
    };
    
    var applications = JSON.parse(localStorage.getItem('seller_applications') || '[]');
    applications.push(applicationData);
    localStorage.setItem('seller_applications', JSON.stringify(applications));
    
    showSuccessUI();
}

function showSuccessUI() {
    var container = document.getElementById('seller-form-content');
    if (container) {
        container.innerHTML = '<div class="success-message"><div class="success-icon gl"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg></div><h3>Application received! 🌱</h3><p>We\'ll review your eco-certification within 48 hours.</p><a href="index.html" class="btn-p" style="margin-top:1rem;display:inline-block">Return to Home</a></div>';
    }
    showToast('Application submitted successfully!', 'success');
}

// ==================== INITIALIZATION ====================
// Wait for auth to initialize then render dashboard
function initSellersPage() {
    // Small delay to ensure currentUser is loaded from auth
    setTimeout(function() {
        renderSellerDashboard();
    }, 100);
}

// Override the auth.js init and add our render
document.addEventListener('DOMContentLoaded', function() {
    // This will run after auth.js initialization
    setTimeout(initSellersPage, 200);
});
