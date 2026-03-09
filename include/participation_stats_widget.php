<!-- Participation Type Statistics Chart -->
<div class="row" style="margin-top: 20px;">
    <div class="col-md-12">
        <div class="panel panel-default" style="border-radius: 10px; overflow: hidden; border: none; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
            <div class="panel-heading" style="background: linear-gradient(135deg, #16213e 0%, #1a1a2e 100%); color: white; padding: 15px;">
                <h4 style="margin: 0; font-family: 'Cairo', sans-serif;"><i class="fa-solid fa-chart-pie"></i> إحصائيات أنواع المشاركة</h4>
            </div>
            <div class="panel-body" style="background: #fff; padding: 20px;">
                <div class="row items-center">
                    <div class="col-md-5">
                        <div style="max-width: 300px; margin: 0 auto;">
                            <canvas id="participationChart"></canvas>
                        </div>
                    </div>
                    <div class="col-md-7">
                        <div id="participationLegend" style="font-family: 'Cairo', sans-serif;">
                            <div class="text-center" style="padding: 40px; color: #666;">
                                <i class="fa fa-spinner fa-spin fa-2x" style="color: #007bff; margin-bottom: 10px;"></i>
                                <p>جاري تحميل البيانات...</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Load participation statistics
    fetch('api/participation_stats.php')
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                document.getElementById('participationLegend').innerHTML = 
                    '<div class="alert alert-danger" style="margin: 20px;">❌ خطأ في تحميل البيانات</div>';
                return;
            }
            
            const stats = data.stats;
            const total = data.total;
            
            // Prepare chart data
            const labels = stats.map(s => s.label);
            const counts = stats.map(s => s.count);
            const colors = stats.map(s => s.color);
            const percentages = stats.map(s => s.percentage);
            
            // Create pie chart
            const ctx = document.getElementById('participationChart').getContext('2d');
            new Chart(ctx, {
                type: 'pie', // Changed to pie as requested
                data: {
                    labels: labels,
                    datasets: [{
                        data: counts,
                        backgroundColor: colors,
                        borderWidth: 2,
                        borderColor: '#fff',
                        hoverOffset: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            rtl: true,
                            textDirection: 'rtl',
                            bodyFont: {
                                family: 'Cairo'
                            },
                            titleFont: {
                                family: 'Cairo'
                            },
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.parsed || 0;
                                    const percentage = percentages[context.dataIndex];
                                    return ' ' + label + ': ' + value + ' (' + percentage + '%)';
                                }
                            }
                        }
                    }
                }
            });
            
            // Create legend with details - IMPROVED UI & COLORS
            let legendHTML = '<div style="display: flex; flex-direction: column; gap: 10px;">';
            
            // Total Card
            legendHTML += `
                <div style="background: #f1f3f5; padding: 15px; border-radius: 8px; text-align: center; margin-bottom: 5px;">
                    <div style="font-size: 14px; color: #adb5bd; margin-bottom: 5px;">إجمالي التسجيلات</div>
                    <strong style="font-size: 24px; color: #16213e;">${total}</strong>
                </div>
            `;
            
            stats.forEach(stat => {
                // Determine text color based on background luminance or fixed darker color
                const textColor = '#333'; 
                
                legendHTML += `
                    <div style="display: flex; align-items: stretch; background: white; border: 1px solid #eee; border-radius: 8px; overflow: hidden; transition: box-shadow 0.2s;">
                        <div style="width: 6px; background-color: ${stat.color};"></div>
                        <div style="flex: 1; padding: 12px 15px; display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <div style="font-weight: 700; font-size: 15px; color: #212529; margin-bottom: 4px;">${stat.label}</div>
                                <div style="font-size: 12px; color: #868e96;">
                                    <span style="color:#28a745">✔ ${stat.approved}</span> <span style="margin:0 4px;color:#ddd">|</span> 
                                    <span style="color:#ffc107">⏳ ${stat.pending}</span> <span style="margin:0 4px;color:#ddd">|</span> 
                                    <span style="color:#dc3545">❌ ${stat.rejected}</span>
                                </div>
                            </div>
                            <div style="text-align: left;">
                                <div style="font-size: 18px; font-weight: 800; color: ${stat.color};">${stat.count}</div>
                                <div style="font-size: 11px; color: #adb5bd;">${stat.percentage}%</div>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            legendHTML += '</div>';
            document.getElementById('participationLegend').innerHTML = legendHTML;
        })
        .catch(err => {
            console.error('Error loading participation stats:', err);
            document.getElementById('participationLegend').innerHTML = 
                '<div class="alert alert-danger" style="margin: 20px;">❌ خطأ في اتصال البيانات</div>';
        });
});
</script>
